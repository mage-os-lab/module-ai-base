# Provider Integration & Customization Guide

This guide is for developers integrating a new AI provider into `MageOS_AiBase`, or
customizing how the module stores, displays, and serves AI service configuration.
For install/usage basics, see the [README](../README.md).

## Concepts

The module separates three concerns, each with its own contract:

| Contract | Role |
|---|---|
| `Api\Data\AiServiceConfigurationInterface` | Describes an **available** backend: code, display name, admin form fields, curated model list |
| `Api\Data\AiServiceInterface` | A **configured instance**: code + the admin-saved configuration values (returned by the selector, credentials already decrypted) |
| `Api\AiServiceSelectorInterface` | Consumer API for reading configured instances (`getAll()`, `getByCode()`) |
| `Api\AiClientInterface` / `Api\AiClientFactoryInterface` | Provider-agnostic client for actually making AI calls |
| `Api\ModelListProviderInterface` | Optional: live-fetch the provider's model list (admin-triggered only) |
| `Api\PlatformArgumentsProviderInterface` | Optional: the arguments the provider's Symfony AI bridge factory takes, when that isn't the API key alone |

Configuration is stored as JSON in `core_config_data` at `mageos_ai/services/configuration`,
shaped `{rowId: {serviceCode: {field: value}}}`. Multiple rows per service code are allowed
(admins may register the same backend twice, e.g. with different keys); `getByCode()` therefore
returns an array, and code paths that need "the" instance use the first row.

## Adding a provider

### 1. The service class

Create a class in your own module that extends `AiServices\AbstractAiService`:

```php
declare(strict_types=1);

namespace Vendor\AcmeAi\AiServices;

use MageOS\AiBase\AiServices\AbstractAiService;

class Acme extends AbstractAiService
{
    public function getCode(): string
    {
        return 'acme';
    }

    public function getName(): string
    {
        return 'Acme AI';
    }

    public function getSupportedModels(): array
    {
        return [
            'acme-large' => 'Acme Large',
            'acme-mini'  => 'Acme Mini',
        ];
    }
}
```

Extend the base class rather than implementing `Api\Data\AiServiceConfigurationInterface`
directly: when that interface gains a method in a minor release, the base class ships a default for
it at the same time, so your provider keeps working. Its constructor takes only the field factory;
if your provider needs more, take it in your own constructor and call
`parent::__construct($fieldFactory)`.

By default a provider gets an API key field plus a model select built from
`getSupportedModels()` (free text when that is empty). Override `getConfigurationFields()` for
anything else, using the protected field builders:

- `apiKeyField()` — password input named `api_key`, **marked encrypted**
- `modelField(array $models)` — select named `model` built from a `value => label` map
- `baseUrlField(string $default, ?string $label = null)` — text input named `base_url` (local runtimes)
- `freeTextModelField()` — text input named `model` (no curated list)

`baseUrlField()` also flags its field as an endpoint; see "Endpoint fields" below.

You can also build fields directly with `FieldDescriptorInterfaceFactory`:

```php
$this->fieldFactory->create([
    'name'      => 'endpoint',
    'label'     => 'Endpoint',
    'type'      => FieldDescriptorInterface::TYPE_TEXT,   // TEXT | PASSWORD | SELECT
    'options'   => [],          // for selects: [['value' => ..., 'label' => ...], ...]
    'default'   => 'https://acme.example/v1',
    'encrypted' => false,
    'endpoint'  => true,        // names the host the row's credentials are sent to
])
```

`encrypted` and `endpoint` both default to `false`, so a `create([...])` call that leaves them
out keeps working.

### Field naming conventions

Use snake_case. Established names — reuse them, several code paths key on them:

| Name | Meaning |
|---|---|
| `api_key` | Credential (encrypted, masked in the form) |
| `model` | Selected model; for Azure this doubles as the deployment name |
| `base_url` | Local-runtime endpoint (Ollama, LM Studio); flagged as an endpoint |
| `endpoint` | Hosted resource endpoint (Azure); flagged as an endpoint |
| `api_version` | Optional API version override (Azure) |

### 2. Encryption is schema-driven

A field is encrypted at rest and masked (`******`) in the admin form **iff its descriptor
sets `'encrypted' => true`**. The admin never sees stored credentials again — saving an
untouched `******` keeps the stored value; typing a new value replaces it.

Fallback: for rows whose service code has no registered configuration class (e.g. a
third-party provider module was removed), sensitivity falls back to a name heuristic. A
field name, lowercased and with `_` and `-` removed, counts as a credential when it ends in
`apikey`, `accesskey`, `secretkey`, `privatekey`, `authkey`, `token`, `secret`, `password`,
`passwd`, `passphrase`, `credential`, `credentials` or `bearer`, so `api_key`, `apiKey`,
`client_secret`, `access_token` and `bearer_token` are covered, while `max_tokens` and
`token_endpoint` are not. Do not rely on the heuristic for new code — it is defense in depth
for an unanticipated field name, not a substitute for marking your fields explicitly.

#### Endpoint fields

Any field whose value decides where a request goes (a base URL, an endpoint, a host, whatever you
call it) must set `'endpoint' => true` on its descriptor (`baseUrlField()` does). Fields named
`base_url` or `endpoint` are guarded by name as well, flag or not. On save, a masked
`******` credential is only restored from storage while every endpoint field of the row still holds
what is stored. If an endpoint changed in the same save, the save is refused and the administrator
has to type the credential again; otherwise anyone who can edit the form could point the row at a
server they control, leave the key masked, press Test Connection and read the stored key off their
own server. A value that is missing from the stored row counts as empty, so filling in an endpoint
where none was stored is a change too; empty on both sides is not. Surrounding space and a trailing
slash are ignored.

For a registered provider the descriptors are authoritative: only fields flagged `endpoint` are
compared, under whatever name. Only rows whose provider is no longer registered fall back to the
names `base_url` and `endpoint`, the same way the credential-name heuristic above stands in for the
`encrypted` flag. A third-party host field that is not flagged is therefore not guarded at all.

### 3. Register in di.xml

One array lists your service (`src/etc/di.xml`):

```xml
<type name="MageOS\AiBase\Model\ServiceRegistry">
    <arguments>
        <argument name="services" xsi:type="array">
            <item name="acme" xsi:type="object">Vendor\AcmeAi\AiServices\Acme</item>
        </argument>
    </arguments>
</type>
```

The admin form, the `ConfiguredService` option source, the sensitive-data processor and the
model-list refresh controller all read this one registry, so there is nothing to keep in sync.
Third-party modules add items to this same argument from their own `di.xml` — Magento merges
array arguments by key, no core edits.

The item name should match `getCode()`; the registry keys by `getCode()` regardless, so a
mismatch is misleading rather than broken.

### 4. Wire a client bridge (optional but recommended)

`AiClientFactoryInterface` builds clients from [symfony/ai-platform](https://github.com/symfony/ai)
bridges. symfony/ai-platform and the OpenAI and Anthropic bridges are required by the module;
every other bridge is a `suggest`, only needed when a client for that provider is actually
created. Bridges are registered per service code. The `bridges` and `dialects`
argument keys shown below are a stable contract within a major version, even though the classes
they configure are not `@api`:

```xml
<type name="MageOS\AiBase\Model\Client\BridgeRegistry">
    <arguments>
        <argument name="bridges" xsi:type="array">
            <item name="acme" xsi:type="array">
                <item name="factory" xsi:type="string">Symfony\AI\Platform\Bridge\Acme\Factory</item>
                <item name="package" xsi:type="string">symfony/ai-acme-platform</item>
                <item name="dialect" xsi:type="string">openai_chat</item>
            </item>
        </argument>
    </arguments>
</type>
```

`factory` is resolved lazily with `class_exists()`/`method_exists('createPlatform')` guards, so
the mapping is safe to ship even when your bridge package is not installed. `package` is what the admin
form tells an administrator to install when the bridge is missing; a provider with no released
bridge omits it and is labelled unsupported instead.

`model_override` (boolean, default `true`) says whether a call can run against another model than
the configured one. Set it to `false` when the bridge fixes the model at platform creation, as
Azure's does with its deployment; an override then throws `AiRequestNotSentException` instead of
being silently ignored.

`dialect` names the request-option shape your provider speaks, which decides how the universal
options (`max_tokens`, `temperature`, `top_p`, `stop`, `tool_choice`, `reasoning_effort`) are
spelled on the wire — see [CONSUMING.md](CONSUMING.md#options). The shipped dialects are
`openai_chat` (the `/v1/chat/completions` body most OpenAI-compatible providers use),
`openai_responses`, `anthropic_messages`, `gemini` and `ollama`; declare your own alongside them
on `Model\Client\OptionNormalizer` if your provider spells them differently:

```xml
<type name="MageOS\AiBase\Model\Client\OptionNormalizer">
    <arguments>
        <argument name="dialects" xsi:type="array">
            <item name="acme" xsi:type="array">
                <item name="map" xsi:type="array">
                    <item name="max_tokens" xsi:type="string">output_budget</item>
                    <item name="temperature" xsi:type="string">temperature</item>
                </item>
                <!-- options the provider wants as an array of strings -->
                <item name="lists" xsi:type="array">
                    <item name="stop" xsi:type="string">stop</item>
                </item>
                <!-- applied only when the caller supplied neither name -->
                <item name="defaults" xsi:type="array">
                    <item name="max_tokens" xsi:type="number">4096</item>
                </item>
                <!-- tool_choice and reasoning_effort: canonical value => request fragment to
                     merge in. {{name}} is replaced with the tool name for `['tool' => '<name>']`. -->
                <item name="values" xsi:type="array">
                    <item name="tool_choice" xsi:type="array">
                        <item name="auto" xsi:type="array">
                            <item name="tool_choice" xsi:type="string">auto</item>
                        </item>
                        <item name="required" xsi:type="array">
                            <item name="tool_choice" xsi:type="string">required</item>
                        </item>
                        <item name="tool" xsi:type="array">
                            <item name="tool_choice" xsi:type="array">
                                <item name="type" xsi:type="string">function</item>
                                <item name="name" xsi:type="string">{{name}}</item>
                            </item>
                        </item>
                    </item>
                </item>
            </item>
        </argument>
    </arguments>
</type>
```

An option absent from `map`, or a canonical value absent from `values`, is treated as unsupported
by that provider and raises a `LocalizedException` naming both, rather than being dropped on the
way to the wire. The exception is `tool_choice: auto`, which every provider treats as its own
default and so is a silent no-op wherever a dialect declares no translation for it at all. A value
outside the canonical set (`auto`, `none`, `required`, `['tool' => '<name>']` for `tool_choice`;
`none`, `low`, `medium`, `high` for `reasoning_effort`) is the provider's own and passes through
as written. Declaring no dialect at all passes every option through untouched.

### Model-dependent values

A dialect is chosen per service code, while the model sits on the configured row, so the `values`
table cannot know which model a request goes to. A few of the shipped translations are accepted by
some models of a provider and rejected with a 400 by others. A store that hits one of these knows
to look at the model configured on the row, not at this module:

| Provider | Value | Caveat |
|---|---|---|
| Anthropic | `reasoning_effort: none` | Becomes `thinking: {type: "disabled"}`, which models whose thinking is always on reject. |
| Anthropic | `tool_choice: required`, `['tool' => '<name>']` | Forced tool choice (`any`, `tool`) is rejected by some recent models. |
| Gemini | `reasoning_effort: none` | Becomes `thinkingBudget: 0`. Models that cannot switch thinking off (2.5 Pro) enforce a minimum budget instead. |
| Gemini | `reasoning_effort: low`/`medium`/`high` | The token budgets (1024, 8192, 24576) are this module's own choice. Newer models use a named thinking level rather than a budget. |

Where a model needs something else, send the provider's own option instead of the neutral one;
it wins over the translation.

Bridge factories disagree on what `createPlatform()` takes first. By default your provider hands
over the API key alone, which is what hosted providers' factories take. If yours takes something
else, override `getPlatformArguments()` (from `Api\PlatformArgumentsProviderInterface`) and return
the leading positional arguments; the client factory adds the optional named ones (HTTP client,
model catalogue) itself:

```php
public function getPlatformArguments(array $configuration): array
{
    return [$this->resolveBaseUrl($configuration, 'http://localhost:8080')];
}
```

The bundled Ollama, LM Studio, OpenAI-Compatible, Opper and Azure providers are worked examples. The
bridge signatures are verified against **symfony/ai-platform v0.14.0**; the component is
experimental with no BC promise, so pin your version and re-verify on upgrade.

A service without a bridge still works for configuration storage — `create('acme')` will
throw a `LocalizedException` explaining no bridge is registered. The admin **Test Connection**
button uses this same path and surfaces these messages verbatim.

### 5. Live model lists (optional)

Implement `Api\ModelListProviderInterface` on your service class to enable the admin
**Refresh Models** button:

```php
public function fetchModels(array $configuration): array
```

It receives the saved (decrypted) configuration and returns a `value => label` map, throwing
`LocalizedException` with an admin-readable message on failure. Inject
`Api\JsonFetcherInterface` (`getJson(url, headers)`: timeouts, non-2xx and JSON errors already
handled, and error messages that name the host rather than the full URL) rather than rolling your
own client. The bundled `OpenAi` and `Ollama` providers show the response parsing. Implementing the interface is all it takes —
the admin form detects it (`supportsModelRefresh` in the schema JSON) and shows the button
automatically.

Refreshing is **manual only** — an admin clicks the button; nothing fetches automatically or
on cron. Fetched lists persist per configured row at config path
`mageos_ai/services/row_models/<row id>`, so two rows of one provider on different hosts keep their
own lists, and take precedence over `getSupportedModels()` via `Model\ModelList\Resolver`, which
is the single merge point (service classes stay pure). Lists stored per code by earlier versions
(`mageos_ai/services/models/<code>`) are still read as a fallback. The curated list remains the fallback for
stores that never refresh.

## Customization recipes

**Override a curated model list** — preference your own class over the service:

```xml
<preference for="MageOS\AiBase\AiServices\OpenAi" type="Vendor\Module\AiServices\OpenAi"/>
```

(or refresh live models from the admin instead — no code needed).

**Replace the client implementation entirely** (e.g. a native Guzzle client, or a proxy
gateway): preference `AiClientFactoryInterface`. Consumers depend only on
`AiClientInterface::complete()` / `getServiceCode()`, so the swap is invisible to them.

```xml
<preference for="MageOS\AiBase\Api\AiClientFactoryInterface" type="Vendor\Module\Model\MyClientFactory"/>
```

**Add behavior around calls** (logging, cost accounting, redaction): a standard plugin on
`AiClientFactoryInterface::create()` or on `AiClientInterface::complete()` — both are DI-served
interfaces, so interceptors apply. Never mark your implementations `final`; Magento generates
interceptors and proxies by subclassing.

**Consume a configured service** — depend on the interfaces, not implementations:

```php
public function __construct(
    private readonly AiClientFactoryInterface $aiClientFactory,   // to make calls
    private readonly AiServiceSelectorInterface $aiServiceSelector, // to read raw config
) {
}
```

## Admin surface reference

- Form: Stores > Configuration > Mage-OS > AI Configuration (`system.xml` field
  `mageos_ai/services/configuration`, backend model `Model\Config\Backend\EncryptedServices`,
  frontend model `Block\Adminhtml\Configuration\Services`).
- ACL: `MageOS_AiBase::configuration`, under Stores > Settings > Configuration in the role tree
  like every other configuration section (also guards the Test Connection and Refresh Models
  controllers under the `mageos_ai` adminhtml route).
- Refresh Models fetches with the provider the requested row is stored as. A posted
  `service_code` that disagrees with the row named by `service_id` is refused, so a crafted request
  cannot send one row's key to another provider's host.
- Encryption key rotation: credentials of every provider, including yours, are re-encrypted
  along with Magento's own config values (see "Security model" in `docs/ARCHITECTURE.md`), as
  long as each credential field is flagged `encrypted`. Nothing to do on the provider side.
- The form's JavaScript is emitted through `SecureHtmlRenderer` and is CSP-compliant; if you
  extend the template, keep script content inside the rendered tag rather than adding inline
  `<script>` blocks.
- Every row shows its provider and model, so the same provider can be added once per account. The
  pencil names a row, and the toggle takes it out of use without deleting its credentials. In
  developer mode the form also offers providers whose bridge package is missing and names the
  package to install; production leaves them out.
- **Test Connection** sends a minimal prompt through the bundled client for the row it sits in and
  shows latency and the response inline. Only saved rows can be tested, since the client reads
  saved configuration. A failure is reported by kind (rejected key, rate limit, unreachable host,
  rejected request); the full error, which can include the request URL, goes to the Magento log
  instead of the page.
- **Refresh Models** fetches the provider's model list with the row's saved credentials, only when
  clicked. A select field gets the list as its options; a free-text model field (OpenRouter,
  Ollama, LM Studio) gets it as autocomplete suggestions, so an unlisted model can still be typed.
- Credentials saved before encryption existed are detected and returned as they are, and
  encrypted on the next save of the form.
- Removing or disabling your provider's module does not delete the rows saved for it. The form
  shows each one as a read-only placeholder (its name and service code, no fields), and saving the
  form keeps it exactly as stored, credentials included; `EncryptedServices` carries it over
  server side whether or not the form posted it. Its delete button is the only thing that removes
  it. Reinstall the module and the row comes back with the same id and credential, so selections
  other modules stored by that id keep working.

## Testing your provider

See `Test/Unit/AiServices/ServicesTest.php` — a parametrized smoke test asserting every
registered service exposes a non-empty code/name, valid field descriptors, and that encrypted
flags are set where expected. Add your class to its data provider (or replicate the pattern in
your own module). For `fetchModels()`, give it a small fake `JsonFetcherInterface` that returns a
canned response, and assert the parsing and failure paths (`Test/Unit/AiServices/ModelListFetchTest.php`
has examples).
