# `module-ai-base` v1.0.0 — Design Doc

**Date:** 2026-04-20
**Author:** David Lambauer
**Status:** Approved, ready for implementation plan

## Goal

Take `mage-os/module-ai-base` from its current `0.x` "works on my machine" state to a released, CI-gated, demo-verified `v1.0.0`. The release pass touches four axes: CI wiring, a breaking API cleanup, a test suite, and a packaging/release sequence. No runtime behaviour changes for consumers that only use `AiServiceSelectorInterface`; the breaking change is confined to `AiServiceConfigurationInterface`, which ships inside this module (no external implementers yet).

## Decisions locked during brainstorming

| # | Axis | Decision |
|---|------|----------|
| Q1 | Scope | Full v1.0.0 release (CI + tests + API cleanup + tag) |
| Q2 | Model list abstraction | `getSupportedModels(): array` method on each `AiServices/*` (no new provider interface) |
| Q3 | Admin form refresh | Structured `FieldDescriptor[]` schema replaces the HTML-template string pattern |
| Q4 | Release mechanics | One-off `v1.0.0` tag, manual packagist submission (no `release-please` for now) |

## Architecture

### Public API — breaking

`Api/Data/AiServiceConfigurationInterface` changes shape:

```php
interface AiServiceConfigurationInterface
{
    public function getCode(): string;
    public function getName(): string;
    /** @return FieldDescriptorInterface[] */
    public function getConfigurationFields(): array;
    /** @return array<string,string> value => label (empty array if N/A) */
    public function getSupportedModels(): array;
}
```

`getConfigurationTemplate(): string` is removed. The rendering concern it carried moves to the phtml template.

### New types

`Api/Data/FieldDescriptorInterface` + `Model/FieldDescriptor` (readonly DTO, built via auto-generated `FieldDescriptorInterfaceFactory`):

```php
interface FieldDescriptorInterface
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_SELECT   = 'select';

    public function getName(): string;
    public function getLabel(): string;
    public function getType(): string;
    public function getOptions(): array;   // [['value' => ..., 'label' => ...], ...]
    public function getDefault(): ?string;
}
```

### Consumer API — unchanged

`AiServiceSelectorInterface::getAll()` and `::getByCode(string $code)` keep their signatures. The on-disk JSON shape stored at `mageos_ai/services/configuration` is unchanged: `{ _rowId: { <service_code>: { <field_name>: <value>, ... } } }`. This means any consumer module that already uses the selector keeps working after the upgrade.

### Internal changes

- `Model/AiServiceSelector::getParsedConfig()` hardened: guard against `ScopeConfigInterface::getValue()` returning `null`, `json_decode()` returning `null` on malformed JSON, and non-array rows.
- `Block/Adminhtml/Configuration/Services::getServicesTemplates()` replaced by `getServicesSchema(): string` returning a JSON blob keyed by service code with field descriptors serialised to arrays.
- `src/view/adminhtml/templates/system/config/form/field/services.phtml` rewritten: drops per-service inline HTML templates, JS loops over the schema and emits `<input type="password">`, `<input type="text">`, or `<select><option>...</option></select>` by field type. Row-level naming (`<%- _fieldName %>[<code>][<field_name>]`) stays identical for backwards on-disk compat.
- `src/etc/module.xml` gains `<sequence><module name="Magento_Config"/><module name="Magento_Backend"/></sequence>` — today the module silently depends on both.
- `declare(strict_types=1)` added to every PHP file.

### Eleven `AiServices/*` rewrites

Each class becomes ~20 lines: constructor takes `FieldDescriptorInterfaceFactory`, `getSupportedModels()` returns the current model list, `getConfigurationFields()` returns the field DTOs. Model lists refreshed against each provider's current public catalog as of 2026-04-20.

## CI

Single workflow: `.github/workflows/check-extension.yaml`.

```yaml
name: Check Extension
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  compute-matrix:
    runs-on: ubuntu-latest
    outputs:
      matrix: ${{ steps.supported.outputs.matrix }}
    steps:
      - uses: actions/checkout@v4
      - uses: graycoreio/github-actions-magento2/supported-version@v5.1.0
        id: supported

  check-extension:
    needs: compute-matrix
    uses: graycoreio/github-actions-magento2/.github/workflows/check-extension.yaml@v5.1.0
    with:
      matrix: ${{ needs.compute-matrix.outputs.matrix }}
```

Both Graycore refs pin to `@v5.1.0` on merge (not `@main`) so the matrix doesn't shift under us between releases.

The four reusable-workflow jobs (`unit-test-extension`, `compile-extension`, `coding-standard`, `integration_test`) all do real work after this PR:

- **phpunit / unit** — 12 test classes execute.
- **setup:di:compile** — catches constructor-arg typos, missing `di.xml` preferences, stale factory references.
- **phpcs** — uses a repo-level `phpcs.xml.dist` extending `Magento2`. Graycore v5.1.0 prefers a project-local config when present.
- **phpunit / integration** — the one round-trip test fires against a real DB.

## Testing

### Layout

```
src/Test/
├── Unit/
│   ├── Model/AiServiceSelectorTest.php
│   └── AiServices/ServicesTest.php
└── Integration/
    └── Model/AiServiceSelectorTest.php
```

All tests `final class`, methods `snake_case`, one assertion per test where feasible (per global CLAUDE.md conventions).

### Unit — `AiServiceSelectorTest`

1. `getAll_returns_empty_array_when_config_is_null` — `ScopeConfigInterface::getValue()` mocked to return `null`.
2. `getAll_returns_empty_array_when_config_is_malformed_json` — mocked to return `"not-json"`.
3. `getAll_returns_all_configured_services` — two rows (openai + anthropic), asserts 2 `AiServiceInterface` with expected `getCode()` + `getConfiguration()`.
4. `getByCode_filters_to_matching_services_only` — three rows, two openai + one anthropic, `getByCode('openai')` returns 2.

All PHPUnit mocks. No bootstrap.

### Unit — `ServicesTest` (parametrised over all 11 services)

Data provider yields every `AiServices/<Name>` class. Test body asserts:
- `getCode()` is non-empty string
- `getName()` is non-empty string
- `getConfigurationFields()` returns non-empty `FieldDescriptorInterface[]`
- `getSupportedModels()` returns an array (may be empty for local-only services like LM Studio / Ollama)

One test method, eleven cases.

### Integration — `AiServiceSelectorTest`

Single test `round_trips_configuration_through_scope_config`:
1. `$resourceConfig->saveConfig('mageos_ai/services/configuration', $json, 'default', 0)`
2. Flush config cache.
3. `$selector->getAll()` → assert 2 `AiServiceInterface` present with expected codes + configuration.

## Release + packaging

### `composer.json` final shape

```json
{
    "name": "mage-os/module-ai-base",
    "description": "Base AI module for Mage-OS — register and retrieve configuration for multiple AI backends.",
    "type": "magento2-module",
    "license": ["OSL-3.0", "AFL-3.0"],
    "authors": [{ "name": "David Lambauer", "email": "david@run-as-root.sh" }],
    "support": {
        "issues": "https://github.com/mage-os/module-ai-base/issues",
        "source": "https://github.com/mage-os/module-ai-base"
    },
    "require": {
        "php": "^8.2",
        "magento/framework": "^103.0 || ^104.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "mage-os/magento-coding-standard": "^2.0"
    },
    "autoload": {
        "files": ["src/registration.php"],
        "psr-4": { "MageOS\\AiBase\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "MageOS\\AiBase\\Test\\": "src/Test/" }
    }
}
```

Dropped: `minimum-stability: dev`. Constrained: `magento/framework`.

### Release sequence

1. Single PR on `main` containing the entire "done done" change set.
2. After merge: `git tag v1.0.0 && git push --tags`.
3. Manual maintainer action: submit repo to packagist.org once. Subsequent tags auto-publish via the webhook.
4. Draft a GitHub Release for `v1.0.0` with seeded `CHANGELOG.md`.

### Repo scaffolding

- `LICENSE` — OSL-3.0 + AFL-3.0 dual (Mage-OS convention).
- `CHANGELOG.md` — `## 1.0.0` section summarising the breaking change and new interface surface.
- `README.md` — fix the current error (example references wrong interface; actual API is `AiServiceSelectorInterface::getAll(): AiServiceInterface[]` / `::getByCode(string $code): AiServiceInterface[]`).
- `phpcs.xml.dist` — extends `Magento2`, scoped to `src/`.

## Demo smoke test

Target: `/Users/david/Herd/mage-os-typesense` (Mage-OS 2.2.0).

1. Add to the demo's `composer.json` `repositories`:
   ```json
   "ai-base": { "type": "path", "url": "/Users/david/Herd/module-ai-base" }
   ```
2. `composer require mage-os/module-ai-base:@dev`
3. `bin/magento module:enable MageOS_AiBase`
4. `bin/magento setup:upgrade && bin/magento setup:di:compile`
5. Log into admin (`david` / `Admin12345!`).
6. **Stores → Configuration → Services → AI Configuration**.
7. Add OpenAI service → API key + model → Add Anthropic service → API key + model → Save.
8. Refresh, verify rows render with correct field types (password masked, select with correct option).
9. Sanity: resolve `AiServiceSelectorInterface` in object manager, `getAll()` returns 2 instances.
10. Delete rows, save, `getAll()` returns `[]` (exercises the null-guard branch).

## Explicitly out of scope

- HTTP calls to any AI provider (consumer modules' concern).
- `release-please` / conventional commits (deferred, revisit post-1.0).
- ui_component rewrite of the admin form (rejected in Q3 — too expensive for the value).
- Migration code for pre-1.0 stored config (no prior release exists).
- Auto-publishing to packagist (requires maintainer's packagist.org account action).

## Risks

- **Breaking interface change.** Mitigated by no external implementers existing yet — `composer.json` is tagged `0.x` and the package isn't on packagist, so there cannot be a third-party class implementing `AiServiceConfigurationInterface` with the old `getConfigurationTemplate()` signature.
- **Graycore matrix drift.** Mitigated by pinning `@v5.1.0` rather than `@main`.
- **Mage-OS 2.2 framework constraint (`^103.0 || ^104.0`).** If Mage-OS 2.3 bumps `magento/framework` major, the constraint needs updating — not a 1.0 blocker.
- **PHPUnit integration test requires DB.** Only runs in `check-extension`'s integration job which provides one; locally skipped unless a bootstrap is configured.
