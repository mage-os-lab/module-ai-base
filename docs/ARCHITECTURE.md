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
  AiClientInterface                 provider-agnostic AI client (consumer API)
  AiClientFactoryInterface          builds clients from saved config (consumer API)
  ModelListProviderInterface        opt-in live model listing (provider SPI)
  Data/
    AiServiceConfigurationInterface describes an available backend (provider SPI)
    AiServiceInterface              a configured instance (code + values)
    FieldDescriptorInterface        one admin form field (name/label/type/options/default/encrypted)

AiServices/                         bundled providers (OpenAi, Anthropic, Azure, ...)
  FieldFactoryTrait                 shared field builders (api_key, model, base_url, ...)
  ModelListTrait                    shared OpenAI-shape model list parsing / base-URL resolution

Model/
  AiServiceSelector                 parses stored JSON -> AiServiceInterface[] (decrypts)
  FieldDescriptor, AiService        value objects behind the Data interfaces
  Config/
    SensitiveDataProcessor          encrypt/decrypt/mask/restore per service schema
    Backend/EncryptedServices       config backend model (save/load hooks)
  Client/
    ClientFactory                   maps service code -> symfony/ai bridge, builds clients
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

`AiServiceSelector::getAll()/getByCode()` reads the path with store scope, defensively
parses (non-string raw, malformed JSON, malformed rows, non-string codes are all skipped,
never thrown), decrypts flagged fields via `SensitiveDataProcessor`, and wraps each row in
an `AiServiceInterface`. Consumers always receive plaintext values.

### Admin display path

`EncryptedServices::_afterLoad()` masks flagged fields with `******` — plaintext
credentials never reach the page DOM. The form JS forces `password` inputs for
encrypted fields regardless of their declared type.

### Client path

`ClientFactory::create(?code)` → first matching configured service → resolves the bridge
FQCN from the di.xml `platformFactories` map → `class_exists`/`method_exists('createPlatform')`
guards → builds a `SymfonyAiClient`. All symfony/ai references are lazy (string FQCNs);
the module compiles and runs without the package installed.

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
2. **Churn isolation** — the component is experimental with no BC promise (bridge factory
   classes and method names have already been renamed between minor versions). The adapter
   (`SymfonyAiClient` + `ClientFactory`) quarantines that churn to two classes; signatures are
   verified against **v0.11.0** and must be re-verified on upgrade.
3. **Pay-for-what-you-use** — config-registry-only consumers shouldn't carry an AI SDK.

Consequences: consumers depend on `AiClientInterface` only; bridges are FQCN strings resolved
lazily with guards; `composer.json` lists the package under `suggest`; native implementations
can replace the whole layer via a `<preference>` without touching consumers. Note that pure
`class_exists` checks on `*Factory` names are unreliable inside Magento test/codegen
environments (factories are auto-generated) — hence the additional `method_exists` guard.

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
