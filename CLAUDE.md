# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`mage-os/module-ai-base` — a small Magento 2 module (`MageOS_AiBase`) that exposes an admin configuration UI for registering multiple AI backends (OpenAI, Anthropic, Azure, Google, Deepseek, HuggingFace, LM Studio, Ollama, OpenRouter) and a consumer API for other modules to read those configured credentials. It does **not** call any AI service itself — it only stores and serves configuration.

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

Admin UI lives at **Stores → Configuration → Mage-OS → AI Configuration**.

## Architecture

There are two intentionally separate interfaces — do not conflate them:

- **`Api\Data\AiServiceConfigurationInterface`** (`getCode`, `getName`, `getConfigurationTemplate`) — describes an *available* backend: its machine code, display name, and the HTML snippet used in the admin form. Implementations live in `src/AiServices/*.php`. These are registered once in `etc/di.xml`, on the `services` array argument of `Model\ServiceRegistry`; the admin form block, the `ConfiguredService` option source, `Model\Config\SensitiveDataProcessor` and the `RefreshModels` controller all read that registry.
- **`Api\Data\AiServiceInterface`** (`getId`, `getCode`, `getConfiguration`) — represents a *configured instance* (stored row id + code + stored credentials/model/etc. array). Produced at runtime by `Model\AiServiceSelector` through `AiServiceInterfaceFactory`. `getId()` is the JSON object key of the row, which the admin form preserves across saves; it is the identity another module stores when an administrator picks a service.

`AiServiceSelectorInterface` is the public consumer API. It resolves at store scope in whatever scope is ambient, and takes no scope argument, so adminhtml/cron/CLI always read the default scope:

```php
AiServiceSelectorInterface::getAll(): AiServiceInterface[]
AiServiceSelectorInterface::getByCode(string $code): AiServiceInterface[]
AiServiceSelectorInterface::getById(string $id): ?AiServiceInterface
```

Consumer modules that want the administrator to choose a service point a `select` field in their own `system.xml` at `Model\Config\Source\ConfiguredService` (or `ConfiguredServiceWithAutomatic`, which prepends an empty-valued "Automatic" option) and resolve the stored row id through `getById()` or `AiClientFactoryInterface::createById()`.

Multiple entries per code are possible because admins can add the same backend multiple times in the UI, which is why `getByCode` returns an array.

Stored data flow:

1. Admin form is an `AbstractFieldArray` rendered via `view/adminhtml/templates/system/config/form/field/services.phtml`.
2. Each `AiServiceConfigurationInterface::getConfigurationTemplate()` returns an HTML fragment using `<%- _fieldName %>` as a `mage/template` placeholder. The phtml wires those into per-row inputs when the admin clicks one of the "Add Service" buttons.
3. Magento serializes the posted rows as JSON via `Magento\Config\Model\Config\Backend\Serialized\ArraySerialized` into `core_config_data` at path **`mageos_ai/services/configuration`**.
4. `AiServiceSelector::getParsedConfig()` reads that path, json_decodes it, and wraps each row with `AiServiceInterfaceFactory`. Each row's structure is `{ _rowId: { <service_code>: { ...fields } } }`, which is why the selector does `array_first(array_keys($item))` to extract the code.

## Adding a new AI backend

1. Create `src/AiServices/<Name>.php` implementing `AiServiceConfigurationInterface`. The configuration template's input `name` attributes must follow `<%- _fieldName %>[<service_code>][<field>]` — that nesting is what the selector expects when reading back.
2. Register it in `etc/di.xml` under the `services` argument of `Model\ServiceRegistry`. The item name should match the class's `getCode()`, which is what the registry keys by.
3. To make it usable through the bundled client, add a `Model\Client\BridgeRegistry` entry with its `factory`, `package` and request-option `dialect` (see `Model\Client\OptionNormalizer` for the dialects).
4. No other wiring is required — the admin UI and selector pick it up automatically.

## Conventions observed in this codebase

- PHP 8 constructor property promotion + `readonly` is the norm; follow it for new classes.
- `declare(strict_types=1)` in every file under `src/`; keep it that way.
- Docblocks on every class, method and constant, including private ones, and they explain *why* rather than restating the signature. This is heavier than most Magento modules; match it.
- `composer.json` requires `php: ^8.2` and `magento/framework: ^103.0.7 || ^104.0` (Magento 2.4.7+, the oldest line whose Symfony components resolve next to symfony/ai-platform). symfony/ai-platform and the OpenAI and Anthropic bridges are hard requirements pinned to `^0.12`; every other bridge stays a `suggest` — see the decision record in `docs/ARCHITECTURE.md`.
- ACL resource: `MageOS_AiBase::configuration` (defined in `etc/acl.xml`), nested under `Magento_Backend::stores_attributes`.
