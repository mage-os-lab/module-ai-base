# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-04-20

### Added
- Structured `FieldDescriptorInterface` config field schema replacing the HTML-template pattern.
- `getSupportedModels(): array` method on each service for non-hardcoded model lists.
- GitHub Actions CI via `graycoreio/github-actions-magento2/check-extension`.
- Unit test suite for `AiServiceSelector` and all eleven `AiServices/*` classes.
- Integration test covering round-trip of stored config through `ScopeConfigInterface`.

### Changed
- **BREAKING:** `AiServiceConfigurationInterface::getConfigurationTemplate(): string` replaced by `::getConfigurationFields(): FieldDescriptorInterface[]` and `::getSupportedModels(): array`.
- `composer.json` now pins `php: ^8.2` and `magento/framework: ^103.0 || ^104.0`.
- `Model/AiServiceSelector` hardened against null scope values and malformed JSON.
- `module.xml` declares explicit dependency on `Magento_Config` + `Magento_Backend`.

### Fixed
- `README.md` API example now references the correct `AiServiceSelectorInterface` (previously cited `AiServiceConfigurationInterface`).
