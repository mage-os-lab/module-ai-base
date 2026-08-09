# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- PHPStan static analysis at level 5, wired into CI as a `static-analysis` job on PHP 8.2 and 8.4. The reusable Magento workflow has no static-analysis step, so this runs the module standalone: phpstan needs the framework classes on the autoloader, not a working install. `bitexpert/phpstan-magento` is what makes analysis of a Magento module viable at all, since without it every reference to an auto-generated `*Factory` is an unknown class (58 errors at level 0 alone). Array shapes are documented throughout so the level can be raised later without re-deriving them.
- The admin form now shows which providers can actually be used through the bundled client. A provider whose Symfony AI bridge package is not installed is greyed out and labelled, with a ready-to-paste `composer require` command listing every missing package; a provider with no bridge released upstream at all (possible for third-party providers) is labelled separately, since there is nothing to install. Greyed-out providers stay configurable, because modules that call the provider with their own HTTP client still read that configuration.

- Per-service "Refresh Models" button in the admin form (saved rows only): POSTs to a new `mageos_ai/service/refreshmodels` adminhtml route (`Controller\Adminhtml\Service\RefreshModels`, reusing the `MageOS_AiBase::configuration` ACL resource) that live-fetches the provider's model list with the saved (decrypted) credentials, persists it at `mageos_ai/services/models/<code>` (default scope, with a fetched-at timestamp) and updates the row's model select in place. Refresh is strictly manual — no automatic or periodic fetching. Providers opt in via the new `Api\ModelListProviderInterface` (`fetchModels(array $configuration): array`); OpenAI, Anthropic, OpenRouter, Ollama and LM Studio implement it. The stored list feeds the form through `Model\ModelList\Resolver`, falling back to each service's curated `getSupportedModels()` when nothing was fetched yet; the service classes themselves stay storage-free.
- Per-service "Test Connection" button in the admin form (saved rows only): POSTs to a new `mageos_ai/service/test` adminhtml route (`Controller\Adminhtml\Service\Test`, reusing the `MageOS_AiBase::configuration` ACL resource) which sends a minimal prompt through `AiClientFactoryInterface` and reports latency and a response snippet (or the error) inline. Requires `symfony/ai-platform`; when several rows share a service code, the first configured row of that code is tested.
- `FieldDescriptorInterface::isEncrypted()`: per-field opt-in flag marking a field as a credential that is encrypted at rest and masked in the admin form (`FieldDescriptor` takes an `encrypted` constructor argument, default `false`). `FieldFactoryTrait::apiKeyField()` sets it, so all bundled providers' `api_key` fields are flagged. The admin form forces `type="password"` inputs for encrypted fields regardless of their declared type.
- `AiClientInterface` / `AiClientFactoryInterface`: provider-agnostic client layer backed by symfony/ai-platform bridges (soft dependency; bridge `Factory` FQCNs mapped per service code in `di.xml`, guarded by `class_exists`/`method_exists`; verified against symfony/ai-platform v0.11.0). Third-party modules can register additional bridges or replace the implementation via `<preference>`.
- Credential fields (`apikey`, `api_key`, `token`, `secret`) are now encrypted at rest via `EncryptedServices` config backend + `SensitiveDataProcessor`. Plaintext rows saved before this change are detected and keep working; they are re-encrypted on the next admin save.
- Azure service: `endpoint` configuration field (required by the Azure OpenAI bridge).
- Unit tests for `SensitiveDataProcessor` and `ClientFactory`.
- Unit tests for the `EncryptedServices` placeholder round-trip and `SensitiveDataProcessor` masking/restore.

### Changed
- **BREAKING:** `AiClientFactoryInterface::create()` called without a service code now returns the first configured service **whose bridge is installed**, rather than simply the first configured service. Admin row order is unrelated to which bridge packages an install has, so the old rule let one unusable provider at the top of the list disable the no-argument entry point for every consumer, even with a usable provider configured below it. When nothing is usable, the error names each configured service and the package it needs. Calling `create('somecode')` explicitly is unchanged and still fails when that provider's bridge is missing, rather than silently resolving to a different provider and billing the wrong account.

- **BREAKING:** the `platformFactories` array argument on `Model\Client\ClientFactory` is replaced by a `Model\Client\BridgeRegistry` collaborator, wired from a single `bridges` argument in `di.xml` that carries both the bridge factory FQCN and the composer package providing it. Third parties registering their own bridge should move their `di.xml` entry to `BridgeRegistry`.

- **BREAKING:** `FieldDescriptorInterface` gained `isEncrypted(): bool`; custom implementations must add it.
- **BREAKING:** `SensitiveDataProcessor` row methods now require the service code as their first argument (`encryptRow`, `decryptRow`, `maskRow`, `restoreRow`), and the class accepts a `services` array (`AiServiceConfigurationInterface[]`, wired in `di.xml`). Sensitivity is decided by the provider field schema (`isEncrypted()`); for unknown service codes or fields not in the schema, the previous field-name heuristic (`apikey`, `api_key`, `token`, `secret`) remains as a fallback — third-party rows may outlive their provider module, and it adds defense in depth.
- **BREAKING:** credential field renamed from `apikey` to `api_key` (form schema, stored config, `ClientFactory` reads). `SensitiveDataProcessor` still treats the legacy `apikey` spelling as sensitive for third-party providers.
- Stored credentials are no longer decrypted into the admin form; they are shown as an obscured `******` placeholder. Saving an unchanged placeholder keeps the previously stored (encrypted) value; typed values replace it. Existing form rows now keep their stored row IDs so placeholders map back to the right row.
- `services.phtml` no longer uses an inline `<script>` block; the script is emitted via `SecureHtmlRenderer::renderTag()` for CSP compliance.
- `AiServiceSelector` reads configuration with store scope (`ScopeInterface::SCOPE_STORE`), enabling per-store service configuration.
- `composer.json`: declare `magento/module-backend`, `module-config`, `module-store` requirements; suggest one bridge package per provider (`symfony/ai-open-ai-platform`, `symfony/ai-anthropic-platform`, and so on) rather than `symfony/ai-platform`, which has shipped no bridges since 0.12; exclude `registration.php` from the classmap.

### Fixed
- `LocalizedException` was given a `\Throwable` as its `$cause`, which its constructor does not accept (`Exception|null`). A provider bridge or HTTP client throwing an `\Error` rather than an `\Exception` would have raised a `TypeError` while reporting the original failure, losing the real cause. Affected `Model\Client\SymfonyAiClient` and `Model\ModelList\HttpFetcher`; the cause is now attached only when it is an `\Exception`, and the message still carries the original text either way.
- `Block\Adminhtml\Configuration\Services::getServicesButtons()` documented a return shape of `{code, name}` while actually returning five keys including `available` and `supported`, which the annotation had been wrong about since provider availability was added. Its `$_addButtonLabel` assignment also passed a `Phrase` where Magento declares a `string`.
- Registered backend definitions coming from `di.xml` are now validated once at the boundary in the admin form block rather than trusted by annotation; DI array arguments are not type-checked by the framework.
- "Refresh Models" is no longer a silent no-op for providers whose model field is free text (OpenRouter, Ollama, LM Studio). The refreshed list was fetched, persisted and reported as a success, but the form only substituted it into a `select`, so half the providers the feature advertises showed nothing. Free-text model fields now render the resolved list as `<datalist>` suggestions and the refresh repopulates them in place. The typed value is left alone: for self-hosted backends the list is a suggestion, not a constraint, which is why the field is free text to begin with.
- `mageos_ai/services/configuration` is now registered with `Magento\Config\Model\Config\TypePool` as `sensitive`, so `bin/magento app:config:dump` no longer writes stored provider credentials into `app/etc/config.php` (commonly committed) and no longer makes the AI Configuration field read-only in the admin, which is what happens once a value is present in the deployment config.

- Admin form field lookups no longer build CSS selectors out of stored data. Row IDs, service codes and field names are POST array keys of the services config value and reach the browser unmodified; interpolated into a selector, one containing a double quote produced an invalid selector, and `querySelector` threw a SyntaxError that aborted the calling loop and left the form half-rendered. Lookups now match the `name` attribute in JavaScript, so the only selector used is a constant.

### Removed
- The `xai` service (xAI / Grok). Symfony AI has no xAI bridge, so the provider could never be used through the bundled client or Test Connection; it is requested upstream in symfony/ai#2371 but unclaimed. Shipping it meant offering a provider in the admin form with nothing an administrator could install to make it work. It can be reinstated as soon as a bridge is released. Installs with an `xai` row configured keep it in `core_config_data` until the AI Configuration page is next saved, at which point the row is dropped along with its credential.

- Duplicate `Grok` service (`grok`): xAI's models are named Grok, so it duplicated the `xai` service. Use `xai`, now displayed as "xAI (Grok)".

## [1.0.0] - 2026-04-21

### Added
- Structured `FieldDescriptorInterface` config field schema replacing the HTML-template pattern.
- `getSupportedModels(): array` method on each service for non-hardcoded model lists. Model lists ship as a curated baseline; admins may override per-install via a `<preference>` on each service class.
- GitHub Actions CI via `graycoreio/github-actions-magento2/check-extension`, matrix-targeted at `project: mage-os`.
- Unit test suite for `AiServiceSelector` (all four guards covered) and a parametrised smoke test exercising all eleven `AiServices/*` classes.
- Integration test covering round-trip of stored config through `ScopeConfigInterface`, with failure-safe cleanup in `tearDown()`.
- `AiServiceSelectorInterface` now documents its insertion-order contract.
- Admin form schema rendering hardens against HTML injection (client-side `escapeHtml()`) and preserves legacy stored values when the model list changes.

### Changed
- **BREAKING:** `AiServiceConfigurationInterface::getConfigurationTemplate(): string` replaced by `::getConfigurationFields(): FieldDescriptorInterface[]` and `::getSupportedModels(): array`.
- `composer.json` now pins `php: ^8.2` and `magento/framework: ^103.0 || ^104.0`.
- `Model/AiServiceSelector` hardened against null scope values and malformed JSON.
- `module.xml` declares explicit dependency on `Magento_Config` + `Magento_Backend`.
- `Block\Adminhtml\Configuration\Services` now validates at runtime that injected services implement `AiServiceConfigurationInterface`. (Classes are intentionally not `final` so Magento can generate interceptors/proxies.)

### Fixed
- `README.md` API example now references the correct `AiServiceSelectorInterface` (previously cited `AiServiceConfigurationInterface`).

[Unreleased]: https://github.com/mage-os-lab/module-ai-base/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/mage-os-lab/module-ai-base/releases/tag/v1.0.0
