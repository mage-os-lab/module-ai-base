# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`mage-os/module-ai-base` — a small Magento 2 module (`MageOS_AiBase`) that exposes an admin configuration UI for registering multiple AI backends (OpenAI, Anthropic, Azure, Google, Deepseek, HuggingFace, LM Studio, Ollama, OpenCode Zen, OpenCode Custom, OpenRouter, OpenAI-Compatible), a provider-agnostic client (`AiClientInterface`) other modules use to actually make calls, and a consumer API for reading the stored configuration directly. Every call made through the bundled client is also recorded — token counts and metadata only, never prompt or response content — into two tables and surfaced on a **Reports > AI Token Usage** dashboard, an admin grid, and a `bin/magento mageos:ai:usage` CLI report; see `docs/USAGE-TRACKING.md`. It does **not** estimate cost, and it cannot record a call made through the `PlatformAwareInterface::getPlatform()` escape hatch, which bypasses the client entirely.

The module is installed into a host Magento 2 app; this repo contains no runnable Magento instance and no build step.

## Commands

In this repo:

```bash
vendor/bin/phpunit --testsuite Unit        # needs a Magento install for the tests that mock generated factories
vendor/bin/phpunit --testsuite Platform    # the chat client layer; needs symfony/ai-platform, run in CI with --fail-on-skipped
vendor/bin/phpstan analyse                 # level 10 over src/, configured in phpstan.neon
vendor/bin/phpcs                           # Magento2 ruleset over src/, configured in phpcs.xml.dist
```

The `Unit` suite is only fully green inside a Magento install: `AiServiceSelectorTest` and
`ClientFactoryTest` mock Magento's auto-generated `*Factory` classes, which the ObjectManager code
generator fabricates only there. CI covers that with a separate job (see
`.github/workflows/check-extension.yaml`), which is why `phpunit.xml.dist` splits out `Platform`.

The `Integration` suite cannot run from this repo at all — it needs a running Magento install with
the integration test framework and a database. From inside one that has this module installed:

```bash
cd dev/tests/integration
../../../vendor/bin/phpunit -c phpunit.xml.dist ../../../vendor/mage-os/module-ai-base/Test/Integration
```

CI runs exactly that, in the reusable workflow's `integration_test` job, which appends this
directory to Magento's own `dev/tests/integration/phpunit.xml.dist` as a testsuite.
`.github/check-extension.json` keeps that job switched on explicitly.

The `End-2-End` suite drives the real admin form in a browser with Playwright:

```bash
cd tests/End-2-End
npm install && npx playwright install chromium
E2E_DISPOSABLE_ENVIRONMENT=1 BASE_URL="https://your-store.test/" \
  ADMIN_USER=... ADMIN_PASSWORD=... npx playwright test
```

Two specs seed usage rows, which needs a way to reach Magento: `E2E_MAGENTO_EXEC` is the command
that gets to it (`docker exec store` in CI) and `E2E_MAGENTO_ROOT` the install's path in there.
Unset, both fall back to running PHP against the local filesystem, which is what a DDEV run wants.

**It deletes every configured AI service on the target install before each spec**, and stored
credentials cannot be read back once gone, which is what `E2E_DISPOSABLE_ENVIRONMENT=1` is there to
make you say out loud. Never point it at an install whose configuration matters. CI runs it in a
throwaway container, once in developer mode and once in production, because the form offers
different providers in each; specs that only apply to one are tagged `@developer-mode` or
`@production-mode`. Run it through DDEV (`ddev exec ...`), never on the host.

Anything reading `system.xml` — the backend model on a field, the config structure — needs
`#[\Magento\TestFramework\Fixture\AppArea('adminhtml')]` on the test class. `system.xml` is only
read into the config structure for that area; elsewhere the field silently has no backend model,
and Magento falls back to the plain config `Value`.

Host-side (run inside a Magento 2 install that has this module via `composer require mage-os/module-ai-base`):

```bash
php bin/magento module:enable MageOS_AiBase
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

Admin UI lives at **Stores → Configuration → Mage-OS → AI Configuration**; usage tracking lives at
**Reports → AI Token Usage**.

## Architecture

There are two intentionally separate interfaces — do not conflate them:

- **`Api\Data\AiServiceConfigurationInterface`** (`getCode`, `getName`, `getConfigurationFields`, `getSupportedModels`) — describes an *available* backend: its machine code, display name, admin form fields and curated model list. Implementations extend `AiServices\AbstractAiService` and live in `src/AiServices/*.php`. These are registered once in `etc/di.xml`, on the `services` array argument of `Model\ServiceRegistry`; the admin form block, the `ConfiguredService` option source, `Model\Config\SensitiveDataProcessor` and the `RefreshModels` controller all read that registry.
- **`Api\Data\AiServiceInterface`** (`getId`, `getCode`, `getConfiguration`) — represents a *configured instance* (stored row id + code + stored credentials/model/etc. array). Produced at runtime by `Model\AiServiceSelector` through `AiServiceInterfaceFactory`. `getId()` is the JSON object key of the row, which the admin form preserves across saves; it is the identity another module stores when an administrator picks a service.

`AiServiceSelectorInterface` is the public consumer API. It resolves at store scope in whatever scope is ambient, and takes no scope argument, so adminhtml/cron/CLI always read the default scope. The one exception is internal: the admin actions that must act on the scope being edited (Test Connection, Refresh Models, the `ConfiguredService` option source) wrap their lookup in `Model\ServiceScope::run()`, which makes `Model\AiServiceSelector` read that website or store scope instead (`Model\Config\ConfigScopeResolver` reads it off the config page's `website`/`store` parameters):

```php
AiServiceSelectorInterface::getAll(): AiServiceInterface[]
AiServiceSelectorInterface::getByCode(string $code): AiServiceInterface[]
AiServiceSelectorInterface::getById(string $id): ?AiServiceInterface
```

Consumer modules that want the administrator to choose a service point a `select` field in their own `system.xml` at `Model\Config\Source\ConfiguredService` (or `ConfiguredServiceWithAutomatic`, which prepends an empty-valued "Automatic" option) and resolve the stored row id through `getById()` or `AiClientFactoryInterface::createById()`. A consumer that only works with specific providers declares a virtual type over either class with the `serviceCodes` array argument set; the dropdown then lists only those providers' rows and, when none is configured, one empty-valued placeholder naming the missing provider (the `WithAutomatic` variant skips the placeholder, since "Automatic" already carries the empty value).

Multiple entries per code are possible because admins can add the same backend multiple times in the UI, which is why `getByCode` returns an array.

`Api\ServiceImporterInterface` (impl. `Model\Config\ServiceImporter`) is the one write API: another module's data patch calls `import()` / `importFromConfig()` to move its own saved credentials into a default-scope row and gets the row id back. It encrypts through `SensitiveDataProcessor` and writes through `StoredServicesStorageInterface`, never through the config backend model, so keep those the single definition of a stored row; see "Import path" in `docs/ARCHITECTURE.md`.

Stored data flow:

1. Admin form is an `AbstractFieldArray` rendered via `view/adminhtml/templates/system/config/form/field/services.phtml`.
2. Each `AiServiceConfigurationInterface::getConfigurationFields()` returns `Api\Data\FieldDescriptorInterface` objects (name, label, type, options, default, encrypted). `Block\Adminhtml\Configuration\Services::getServicesSchemaJson()` turns them into a JSON schema keyed by service code, and the JavaScript in services.phtml builds the per-row inputs from it when the admin clicks one of the "Add Service" buttons. Providers never emit HTML.
3. `Model\Config\Backend\EncryptedServices` (an `ArraySerialized` subclass) encrypts the flagged fields and stores the posted rows as JSON in `core_config_data` at path **`mageos_ai/services/configuration`**.
4. `AiServiceSelector::getParsedConfig()` reads that path, json_decodes it, decrypts the flagged fields and wraps each row with `AiServiceInterfaceFactory`. Each row's structure is `{ _rowId: { <service_code>: { ...fields } } }`, which is why the selector does `array_key_first($row)` to extract the code.

**Usage tracking** adds two real database tables (not `core_config_data`), declared in `etc/db_schema.xml`:

- `mageos_ai_usage_log`: one row per call the client actually sent, not only the ones that succeeded (service id/code, model, consumer, store id, six independently-nullable token counts: prompt, completion, total, cache read, cache write, reasoning, plus whether it streamed, whether it failed, `created_at`). `Model\Client\RecordingAiClient` / `RecordingPlatformAwareAiClient` write to it; `ClientFactory` decides whether to wrap a built client with either, based on `Model\Usage\UsageConfig::isEnabled()` and whether the client is `PlatformAwareInterface`. A call `Exceptions\AiRequestNotSentException` rejected before it reached the provider gets no row: nothing was billed for it.
- `mageos_ai_usage_daily` — one row per (`usage_date`, service id, model, consumer, store id) grouping key per day, written by `Cron\RollUpUsage` / `Model\Usage\UsageMaintenance` before it prunes the raw table. Both tables' pruning windows are the `retention_days` / `daily_retention_days` fields under `mageos_ai/usage/*`.

`Api\UsageStatsInterface` (impl. `Model\Usage\UsageStats`) is the one read contract behind the **Reports → AI Token Usage** dashboard, its grid, and `bin/magento mageos:ai:usage`; it merges both tables per `Api\Data\Period`, never double-counting a day present in both. See `docs/ARCHITECTURE.md` for the full recording/roll-up data flows and the decision record on what is deliberately out of scope (no content, no cost estimate, no tracking past `getPlatform()`).

## Adding a new AI backend

1. Create `src/AiServices/<Name>.php` extending `AiServices\AbstractAiService` (never implement `AiServiceConfigurationInterface` directly; the base class is what keeps providers working when that interface gains a method). Override `getSupportedModels()` and, when the defaults (API key + model) don't fit, `getConfigurationFields()` using the protected field builders.
   **Every field that holds a credential must set `'encrypted' => true` on its descriptor** (`AbstractAiService::apiKeyField()` does). That flag is what encrypts the value at rest and masks it in the form. `Model\Config\SensitiveDataProcessor` also has a name-based fallback for rows whose provider is no longer registered, but it only recognises common credential names (`api_key`, `client_secret`, `access_token`, `password`, ...) and exists as defense in depth, not as the mechanism.
2. Register it in `etc/di.xml` under the `services` argument of `Model\ServiceRegistry`. The item name should match the class's `getCode()`, which is what the registry keys by.
3. To make it usable through the bundled client, add a `Model\Client\BridgeRegistry` entry with its `factory`, `package` and request-option `dialect` (see `Model\Client\OptionNormalizer` for the dialects). If the bridge factory's `createPlatform()` doesn't take the API key first, override `getPlatformArguments()` (`Api\PlatformArgumentsProviderInterface`); `ClientFactory` has no per-provider code. Neither `factory` nor `package` has to be a `symfony/*` one: `opencode-zen` and `opencode-custom` point at `MageOS\AiOpenCodeZenPlatform\Factory` and `MageOS\AiOpenCodeCustomPlatform\Factory` from Mage-OS's own bridge packages. A factory that declares a `baseUrl`, `username` or `agent` parameter additionally gets the row's stored `base_url`, `username` or `agent` by name (`ClientFactory::ROW_ARGUMENTS`), unless the provider already fills that position through `getPlatformArguments()`.
4. For a live model list, implement `Api\ModelListProviderInterface` and inject `Api\JsonFetcherInterface`.
5. No other wiring is required — the admin UI and selector pick it up automatically.

## Conventions observed in this codebase

- PHP 8 constructor property promotion + `readonly` is the norm; follow it for new classes.
- `declare(strict_types=1)` in every file under `src/`; keep it that way.
- Docblocks on every class, method and constant, including private ones, and they explain *why* rather than restating the signature. This is heavier than most Magento modules; match it.
- `composer.json` requires `php: ^8.2` and `magento/framework: ^103.0.8 || ^104.0` (Magento 2.4.8+ / Mage-OS 1.1+; 2.4.7's project template pins MFTF 4.7, whose symfony/event-dispatcher ^6.4 cannot resolve next to symfony/ai-platform, see docs/ARCHITECTURE.md). symfony/ai-platform and the OpenAI and Anthropic bridges are hard requirements pinned to `^0.15`; every other bridge stays a `suggest` — see the decision record in `docs/ARCHITECTURE.md`.
- ACL resources (both in `etc/acl.xml`): `MageOS_AiBase::configuration` sits under `Magento_Backend::stores_settings` > `Magento_Config::config` with the other configuration sections, and `MageOS_AiBase::usage` under `Magento_Reports::report` > `MageOS_AiBase::reports`, beside its menu item. Never nest the configuration resource under something a non-admin role is routinely given (it guards credentials and outbound requests), and never change either id: role grants are stored by id.
