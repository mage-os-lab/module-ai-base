# Architecture

How `MageOS_AiBase` is put together: components, data flows, storage formats, and the
security model. Audience: contributors and integrators who need to know *why* things are
shaped the way they are. For task-oriented guides see
[PROVIDERS.md](PROVIDERS.md) (integrating/customizing) and [CONSUMING.md](CONSUMING.md)
(using the module from other code).

## Component map

```
Api/
  AiServiceSelectorInterface        read configured services (consumer API)
  AiClientInterface                 provider-agnostic AI client: chat, streamChat, complete
  AiClientFactoryInterface          builds clients from saved config (consumer API)
  ChatRequestBuilderInterface       assembles a request without naming a Model class
  PlatformAwareInterface            opt-in escape hatch to the raw symfony/ai Platform
  ModelListProviderInterface        opt-in live model listing (provider SPI)
  Data/
    AiServiceConfigurationInterface describes an available backend (provider SPI)
    AiServiceInterface              a configured instance (row id + code + values)
    FieldDescriptorInterface        one admin form field (name/label/type/options/default/encrypted)
    ChatRequestInterface            conversation plus offered tools (immutable)
    ChatResponseInterface           text, tool calls, usage, finish reason
    FinishReason                    why the model stopped, normalized across providers
    ChatMessageInterface            one turn; roles via the MessageRole enum
    ToolDefinitionInterface         a tool offered to the model
    ToolCallInterface               one invocation the model asked for
    TokenUsageInterface             prompt/completion/total, every count nullable
    StreamChunkInterface            one streamed event, typed by StreamChunkType

AiServices/                         bundled providers (OpenAi, Anthropic, Azure, ...)
  FieldFactoryTrait                 shared field builders (api_key, model, base_url, ...)
  ModelListTrait                    shared OpenAI-shape model list parsing / base-URL resolution

Model/
  AiServiceSelector                 parses stored JSON -> AiServiceInterface[] (decrypts, memoized)
  ServiceRegistry                   the registered backends, keyed by code (one di.xml list)
  FieldDescriptor, AiService        value objects behind the Data interfaces
  Config/
    SensitiveDataProcessor          encrypt/decrypt/mask/restore per service schema
    Backend/EncryptedServices       config backend model (save/load hooks)
    Source/ConfiguredService        option source for consumer modules' own system.xml fields
    Source/ConfiguredServiceWithAutomatic  the same, plus an empty-valued "Automatic" option
  Chat/                             value objects behind the chat contracts
    ChatRequestBuilder              the builder behind ChatRequestBuilderInterface
  Client/
    BridgeRegistry                  service code -> bridge factory, package and option dialect
    ClientFactory                   maps service code -> symfony/ai bridge, builds clients
    OptionNormalizer                universal options -> the target provider's own names
    SymfonyAiClient                 adapter around a symfony/ai Platform
  ModelList/
    HttpFetcher                     shared HTTP/JSON plumbing for model fetching
    Storage                         persists fetched lists per service code
    Resolver                        stored list ?? curated getSupportedModels()

Block/Adminhtml/Configuration/Services   admin form frontend model (schema JSON for JS)
Controller/Adminhtml/Service/
  Test                              Test Connection endpoint (JSON)
  RefreshModels                     manual model list refresh endpoint (JSON)
```

## Data flows

### Save path (admin form → database)

1. The form is an `AbstractFieldArray`; rows are built client-side from a schema JSON
   emitted by the Block (per service: `fields` descriptors + a `supportsModelRefresh` flag).
2. POST hits `EncryptedServices` (the `backend_model` in `system.xml`):
   - `restoreRow()` — any submitted `******` placeholder is replaced by the previously
     stored (still encrypted) value for that row/service/field, so saving without retyping
     keeps credentials. Row identity relies on the form reusing stored row IDs.
   - `encryptRow()` — descriptor-flagged fields are encrypted with Magento's
     `EncryptorInterface`. Encryption is idempotent: values already carrying the encryptor
     envelope (`N:N:...`) are left alone.
3. `ArraySerialized` serializes rows to JSON at `mageos_ai/services/configuration`.

### Read path (database → consumers)

`AiServiceSelector::getAll()/getByCode()/getById()` reads the path with store scope,
defensively parses (non-string raw, malformed JSON, malformed rows, non-string codes are all
skipped, never thrown), decrypts flagged fields via `SensitiveDataProcessor`, and wraps each
row in an `AiServiceInterface`. Consumers always receive plaintext values.

The JSON object key of each row becomes `AiServiceInterface::getId()`. That key is the row id
Magento's `AbstractFieldArray` assigns and the form preserves across saves, which is what makes
it safe for another module to store as a reference to "the service the administrator picked"
(`Model\Config\Source\ConfiguredService` → `getById()` / `AiClientFactoryInterface::createById()`).
The service code cannot fill that role, because the same backend may be registered several times
with different credentials or models. A row deleted in the admin makes stored ids stale by
design: the selector answers `null` and the client factory throws, rather than resolving to a
different row and billing an account nobody chose.

### Admin display path

`EncryptedServices::_afterLoad()` masks flagged fields with `******` — plaintext
credentials never reach the page DOM. The form JS forces `password` inputs for
encrypted fields regardless of their declared type.

### Client path

`ClientFactory::create(?code)` → first matching configured service → resolves the bridge
FQCN from `BridgeRegistry` → `class_exists`/`method_exists('createPlatform')` guards → builds a
`SymfonyAiClient` carrying the platform, the model, the service code and the row id. All
symfony/ai references are lazy (string FQCNs); the module compiles and runs without the
package installed.

Per call, `SymfonyAiClient` runs the caller's options through `OptionNormalizer` before handing
them to the platform. Bridges merge options into the provider's request body nearly untouched
and OpenAI-compatible endpoints reject unknown body fields with a 400, so the same
`max_tokens` is `max_output_tokens` on the Responses API, `maxOutputTokens` on Gemini and
`num_predict` on Ollama, and Anthropic rejects a request that omits it entirely. Which shape a
provider speaks is the `dialect` on its `BridgeRegistry` entry; the dialects themselves are
di.xml data, so a third party registering a provider declares one alongside its bridge. Only the
universal four are touched — everything else reaches the provider verbatim.

Responses carry the stop reason both ways: `getFinishReason()` is a normalized `FinishReason`
(the platform's per-bridge mappers do the provider translation), `getRawFinishReason()` keeps the
provider's wording. `streamChat()` yields chunks and then *returns* the assembled
`ChatResponseInterface`, because the platform lifts final token counts and the stop reason out of
the delta sequence into result metadata — a client only watching deltas would report neither.

### Model refresh path (manual only)

Admin clicks Refresh Models → `RefreshModels` controller → the service's `fetchModels()`
(via `HttpFetcher`, Magento's HTTP client) → `Storage::save()` at
`mageos_ai/services/models/<code>` (which also cleans the config cache so the change is
live immediately) → response updates the form select in place.
`Resolver` is the single merge point: stored list if present, else the curated
`getSupportedModels()`. There is intentionally no cron/automatic fetching: no background
HTTP with credentials, no cache-invalidation policy, and the admin sees exactly when and
why a list changed.

## Storage formats

| Path | Content |
|---|---|
| `mageos_ai/services/configuration` | JSON `{rowId: {serviceCode: {field: value}}}`; flagged fields encrypted |
| `mageos_ai/services/models/<code>` | JSON `{models: {value: label}, fetched_at: <ts>}` from the last manual refresh |

Row IDs are opaque strings generated by the form (`_<time>_<ms>`) and preserved across
saves so credential restore can match rows.

## Security model

- **Encryption at rest**: descriptor-flagged fields (`'encrypted' => true`) via
  `EncryptorInterface`. Schema-driven; a name heuristic (`apikey`/`api_key`/`token`/`secret`)
  applies only to rows whose provider class is no longer registered (defense in depth for
  removed third-party modules).
- **No plaintext in the admin**: masked on load, restored on save (see flows above).
- **Legacy tolerance**: values without the encryptor envelope are treated as plaintext and
  pass through reads unchanged; they get encrypted on the next admin save.
- **CSP**: all form JavaScript is emitted through `SecureHtmlRenderer` (hash/nonce), safe
  under strict admin CSP (Magento 2.4.7+).
- **Endpoints**: both admin controllers are POST-only, form-key validated (enforced by
  the `Backend\App\AbstractAction` plugin chain — which is why they extend `Backend\App\Action`
  rather than using pure composition), and gated by the `MageOS_AiBase::configuration` ACL.
- **Outbound calls**: happen only on explicit admin action (Test Connection, Refresh Models)
  or when a consumer module invokes the client API. The module itself never calls providers
  in the background.

## Decision record: symfony/ai as a soft dependency

The client layer adapts [symfony/ai-platform](https://github.com/symfony/ai) rather than
hand-rolling per-provider HTTP clients, but deliberately does **not** `require` it:

1. **Installability** — symfony/ai-platform requires Symfony 7.3+ components; Magento/Mage-OS
   releases ship older Symfony lines. A hard require could make this module uninstallable on
   real stores, which is fatal for a base module the ecosystem depends on.
2. **Churn isolation** — the component is experimental with no BC promise. Its README says so
   outright: *"This Component is experimental. Experimental features are not covered by
   Symfony's Backward Compatibility Promise."* That is not hypothetical on the surface a tool
   loop touches most: its changelog reworked `Message::ofAssistant()` in 0.9 and
   `Message::ofToolCall()` in 0.11, and 0.12 moved every bridge into its own package. The
   adapter (`SymfonyAiClient` + `ClientFactory`) quarantines that churn to two classes;
   signatures are verified against **v0.12.0** and must be re-verified on upgrade.
3. **Pay-for-what-you-use** — config-registry-only consumers shouldn't carry an AI SDK.

Consequences: consumers depend on `AiClientInterface` only; bridges are FQCN strings resolved
lazily with guards; `composer.json` lists the package under `suggest`; native implementations
can replace the whole layer via a `<preference>` without touching consumers. Note that pure
`class_exists` checks on `*Factory` names are unreliable inside Magento test/codegen
environments (factories are auto-generated) — hence the additional `method_exists` guard.

### Why there is an escape hatch anyway

`AiClientInterface` is chat, streaming and completion. symfony/ai-platform is much larger:
executed tool loops (`symfony/ai-agent`), message stores (`symfony/ai-chat`), structured output,
embeddings, vector stores, image and audio. Mirroring all of it would mean re-describing an API
that already exists, and every addition upstream would become a porting task here.

So `Api\PlatformAwareInterface` hands over the platform this module already built, with
credentials resolved and the bridge selected, and the module's unique contribution (admin UI,
credential encryption, multi-row configuration, row selection) stays the thing it owns.

The cost is real and is priced deliberately:

- It is a **separate interface**, reached by `instanceof`, not a method on `AiClientInterface`.
  The coupling is therefore visible at the call site, and a store preferencing its own client
  stack simply does not implement it rather than being forced to fake a Symfony platform.
- It returns `object`, not `PlatformInterface`, so the soft dependency survives: the module
  stays loadable and compilable on an install that never installed symfony/ai-platform.
- Everything past `getPlatform()` sits outside this module's compatibility promise, and both
  README.md and CONSUMING.md say so where a reader will meet it.
- `normalizeOptions()` rides along, because calling the platform directly otherwise silently
  opts out of the option translation described under "Client path" above. That is the one part
  of the client layer worth keeping when the rest is bypassed, so it is offered on its own
  rather than being reachable only through `chat()`.

Note the tool-execution boundary moves for anyone taking this route: this module never executes
tools (whether a call may run is policy belonging to the module owning the tool), while
`symfony/ai-agent`'s `Toolbox` does. That is a defensible place for the decision to live, but it
should be taken knowingly.

## Testing strategy

- Unit tests live in `Test/Unit`, run standalone (`vendor/bin/phpunit --testsuite Unit`)
  and in CI inside a full Mage-OS install (Graycore `check-extension`).
- Standalone runs show environmental errors for mocks of Magento-generated `*Factory`
  classes (they only exist in a full install) — these are expected; CI is authoritative.
  `Test/Unit/Stubs` provides a class_exists-guarded stand-in for
  `FieldDescriptorInterfaceFactory` so provider tests still run standalone.
- Tests needing `Magento\Config`/`Magento\Backend` classes guard-skip when those modules
  aren't autoloadable, so the standalone suite stays green-ish everywhere.
- One integration test covers the config round-trip through `ScopeConfigInterface`.
