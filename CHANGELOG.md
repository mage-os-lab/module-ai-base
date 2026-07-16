# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `AiClientInterface` / `AiClientFactoryInterface`: provider-agnostic client layer backed by symfony/ai-platform bridges (soft dependency; bridge FQCNs mapped per service code in `di.xml`, guarded by `class_exists`). Third-party modules can register additional bridges or replace the implementation via `<preference>`.
- Credential fields (`apikey`, `api_key`, `token`, `secret`) are now encrypted at rest via `EncryptedServices` config backend + `SensitiveDataProcessor`. Plaintext rows saved before this change are detected and keep working; they are re-encrypted on the next admin save.
- Azure service: `endpoint` configuration field (required by the Azure OpenAI bridge).
- Unit tests for `SensitiveDataProcessor` and `ClientFactory`.

### Changed
- `AiServiceSelector` reads configuration with store scope (`ScopeInterface::SCOPE_STORE`), enabling per-store service configuration.
- `composer.json`: declare `magento/module-backend`, `module-config`, `module-store` requirements; suggest `symfony/ai-platform`; exclude `registration.php` from the classmap.

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
