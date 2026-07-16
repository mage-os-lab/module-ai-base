# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Per-service "Refresh Models" button in the admin form (saved rows only): POSTs to a new `mageos_ai/service/refreshmodels` adminhtml route (`Controller\Adminhtml\Service\RefreshModels`, reusing the `MageOS_AiBase::configuration` ACL resource) that live-fetches the provider's model list with the saved (decrypted) credentials, persists it at `mageos_ai/services/models/<code>` (default scope, with a fetched-at timestamp) and updates the row's model select in place. Refresh is strictly manual — no automatic or periodic fetching. Providers opt in via the new `Api\ModelListProviderInterface` (`fetchModels(array $configuration): array`); OpenAI, Anthropic, xAI, OpenRouter, Ollama and LM Studio implement it. The stored list feeds the form through `Model\ModelList\Resolver`, falling back to each service's curated `getSupportedModels()` when nothing was fetched yet; the service classes themselves stay storage-free.
- Per-service "Test Connection" button in the admin form (saved rows only): POSTs to a new `mageos_ai/service/test` adminhtml route (`Controller\Adminhtml\Service\Test`, reusing the `MageOS_AiBase::configuration` ACL resource) which sends a minimal prompt through `AiClientFactoryInterface` and reports latency and a response snippet (or the error) inline. Requires `symfony/ai-platform`; when several rows share a service code, the first configured row of that code is tested.
- `FieldDescriptorInterface::isEncrypted()`: per-field opt-in flag marking a field as a credential that is encrypted at rest and masked in the admin form (`FieldDescriptor` takes an `encrypted` constructor argument, default `false`). `FieldFactoryTrait::apiKeyField()` sets it, so all bundled providers' `api_key` fields are flagged. The admin form forces `type="password"` inputs for encrypted fields regardless of their declared type.
- `AiClientInterface` / `AiClientFactoryInterface`: provider-agnostic client layer backed by symfony/ai-platform bridges (soft dependency; bridge `Factory` FQCNs mapped per service code in `di.xml`, guarded by `class_exists`/`method_exists`; verified against symfony/ai-platform v0.11.0). xAI (Grok) has no dedicated upstream bridge yet and is not mapped. Third-party modules can register additional bridges or replace the implementation via `<preference>`.
- Credential fields (`apikey`, `api_key`, `token`, `secret`) are now encrypted at rest via `EncryptedServices` config backend + `SensitiveDataProcessor`. Plaintext rows saved before this change are detected and keep working; they are re-encrypted on the next admin save.
- Azure service: `endpoint` configuration field (required by the Azure OpenAI bridge).
- Unit tests for `SensitiveDataProcessor` and `ClientFactory`.
- Unit tests for the `EncryptedServices` placeholder round-trip and `SensitiveDataProcessor` masking/restore.

### Changed
- **BREAKING:** `FieldDescriptorInterface` gained `isEncrypted(): bool`; custom implementations must add it.
- **BREAKING:** `SensitiveDataProcessor` row methods now require the service code as their first argument (`encryptRow`, `decryptRow`, `maskRow`, `restoreRow`), and the class accepts a `services` array (`AiServiceConfigurationInterface[]`, wired in `di.xml`). Sensitivity is decided by the provider field schema (`isEncrypted()`); for unknown service codes or fields not in the schema, the previous field-name heuristic (`apikey`, `api_key`, `token`, `secret`) remains as a fallback — third-party rows may outlive their provider module, and it adds defense in depth.
- **BREAKING:** credential field renamed from `apikey` to `api_key` (form schema, stored config, `ClientFactory` reads). `SensitiveDataProcessor` still treats the legacy `apikey` spelling as sensitive for third-party providers.
- Stored credentials are no longer decrypted into the admin form; they are shown as an obscured `******` placeholder. Saving an unchanged placeholder keeps the previously stored (encrypted) value; typed values replace it. Existing form rows now keep their stored row IDs so placeholders map back to the right row.
- `services.phtml` no longer uses an inline `<script>` block; the script is emitted via `SecureHtmlRenderer::renderTag()` for CSP compliance.
- `AiServiceSelector` reads configuration with store scope (`ScopeInterface::SCOPE_STORE`), enabling per-store service configuration.
- `composer.json`: declare `magento/module-backend`, `module-config`, `module-store` requirements; suggest `symfony/ai-platform`; exclude `registration.php` from the classmap.

### Removed
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
