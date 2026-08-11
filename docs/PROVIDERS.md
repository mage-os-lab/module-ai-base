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

Configuration is stored as JSON in `core_config_data` at `mageos_ai/services/configuration`,
shaped `{rowId: {serviceCode: {field: value}}}`. Multiple rows per service code are allowed
(admins may register the same backend twice, e.g. with different keys); `getByCode()` therefore
returns an array, and code paths that need "the" instance use the first row.

## Adding a provider

### 1. The service class

Create `src/AiServices/<Name>.php` implementing `AiServiceConfigurationInterface`:

```php
declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

class Acme implements AiServiceConfigurationInterface
{
    use FieldFactoryTrait;

    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {
    }

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

    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField($this->fieldFactory),
            $this->modelField($this->fieldFactory, $this->getSupportedModels()),
        ];
    }
}
```

`FieldFactoryTrait` provides the standard field builders:

- `apiKeyField()` — password input named `api_key`, **marked encrypted**
- `modelField()` — select named `model` built from a `value => label` map
- `baseUrlField()` — text input named `base_url` with a default (local runtimes)
- `freeTextModelField()` — text input named `model` (no curated list)

You can also build fields directly with `FieldDescriptorInterfaceFactory`:

```php
$this->fieldFactory->create([
    'name'      => 'endpoint',
    'label'     => 'Endpoint',
    'type'      => FieldDescriptorInterface::TYPE_TEXT,   // TEXT | PASSWORD | SELECT
    'options'   => [],          // for selects: [['value' => ..., 'label' => ...], ...]
    'default'   => 'https://acme.example/v1',
    'encrypted' => false,
])
```

### Field naming conventions

Use snake_case. Established names — reuse them, several code paths key on them:

| Name | Meaning |
|---|---|
| `api_key` | Credential (encrypted, masked in the form) |
| `model` | Selected model; for Azure this doubles as the deployment name |
| `base_url` | Local-runtime endpoint (Ollama, LM Studio) |
| `endpoint` | Hosted resource endpoint (Azure) |
| `api_version` | Optional API version override (Azure) |

### 2. Encryption is schema-driven

A field is encrypted at rest and masked (`******`) in the admin form **iff its descriptor
sets `'encrypted' => true`**. The admin never sees stored credentials again — saving an
untouched `******` keeps the stored value; typing a new value replaces it.

Fallback: for rows whose service code has no registered configuration class (e.g. a
third-party provider module was removed), sensitivity falls back to a name heuristic
(`apikey`, `api_key`, `token`, `secret`). Do not rely on the heuristic for new code —
mark your fields explicitly.

### 3. Register in di.xml

One array lists your service (`src/etc/di.xml`):

```xml
<type name="MageOS\AiBase\Model\ServiceRegistry">
    <arguments>
        <argument name="services" xsi:type="array">
            <item name="acme" xsi:type="object">MageOS\AiBase\AiServices\Acme</item>
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
bridges — a **soft dependency** (the package is only required when a client is actually created;
`composer suggest`s it). Bridges are registered per service code:

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
the mapping is safe to ship even when symfony/ai-platform is absent. `package` is what the admin
form tells an administrator to install when the bridge is missing; a provider with no released
bridge omits it and is labelled unsupported instead.

`dialect` names the request-option shape your provider speaks, which decides how the universal
options (`max_tokens`, `temperature`, `top_p`, `stop`) are spelled on the wire — see
[CONSUMING.md](CONSUMING.md#options). The shipped dialects are `openai_chat` (the
`/v1/chat/completions` body most OpenAI-compatible providers use), `openai_responses`,
`anthropic_messages`, `gemini` and `ollama`; declare your own alongside them on
`Model\Client\OptionNormalizer` if your provider spells them differently:

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
            </item>
        </argument>
    </arguments>
</type>
```

An option absent from `map` is treated as unsupported by that provider and raises a
`LocalizedException` naming both, rather than being dropped on the way to the wire. Declaring no
dialect at all passes every option through untouched.

The factory signatures are verified against **symfony/ai-platform v0.12.0**; the component is
experimental with no BC promise — pin your version and re-verify on upgrade. Hosted providers
pass the API key; local runtimes pass `base_url`; Azure passes endpoint/deployment/api_version/key
(see `Model\Client\ClientFactory::createPlatform()` for the dispatch).

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
`LocalizedException` with an admin-readable message on failure. Inject the shared
`Model\ModelList\HttpFetcher` (`getJson(url, headers)` — timeouts, non-2xx and JSON errors
already handled) rather than rolling your own client, and see `AiServices\ModelListTrait`
for ready-made OpenAI-shape response parsing. Implementing the interface is all it takes —
the admin form detects it (`supportsModelRefresh` in the schema JSON) and shows the button
automatically.

Refreshing is **manual only** — an admin clicks the button; nothing fetches automatically or
on cron. Fetched lists persist per service code at config path `mageos_ai/services/models/<code>`
and take precedence over `getSupportedModels()` via `Model\ModelList\Resolver`, which is the
single merge point (service classes stay pure). The curated list remains the fallback for
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
- ACL: `MageOS_AiBase::configuration` (also guards the Test Connection and Refresh Models
  controllers under the `mageos_ai` adminhtml route).
- The form's JavaScript is emitted through `SecureHtmlRenderer` and is CSP-compliant; if you
  extend the template, keep script content inside the rendered tag rather than adding inline
  `<script>` blocks.

## Testing your provider

See `Test/Unit/AiServices/ServicesTest.php` — a parametrized smoke test asserting every
registered service exposes a non-empty code/name, valid field descriptors, and that encrypted
flags are set where expected. Add your class to its data provider (or replicate the pattern in
your own module). For `fetchModels()`, mock `HttpFetcher` and assert the response-shape parsing
and failure paths (`Test/Unit/Model/ModelList/` has examples).
