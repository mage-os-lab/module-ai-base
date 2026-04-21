# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- `Block\Adminhtml\Configuration\Services` is now `final` with runtime validation that injected services implement `AiServiceConfigurationInterface`.

### Fixed
- `README.md` API example now references the correct `AiServiceSelectorInterface` (previously cited `AiServiceConfigurationInterface`).

[Unreleased]: https://github.com/mage-os-lab/module-ai-base/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/mage-os-lab/module-ai-base/releases/tag/v1.0.0
