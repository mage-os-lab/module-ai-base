# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`mage-os/module-ai-base` — a small Magento 2 module (`MageOS_AiBase`) that exposes an admin configuration UI for registering multiple AI backends (OpenAI, Anthropic, Azure, Google, Deepseek, HuggingFace, LM Studio, Ollama, OpenRouter, xAI (Grok)) and a consumer API for other modules to read those configured credentials. It does **not** call any AI service itself — it only stores and serves configuration.

The module is installed into a host Magento 2 app; this repo contains no runnable Magento instance. There is no test suite, no lint/format config, and no build step.

## Commands

Host-side (run inside a Magento 2 install that has this module via `composer require mage-os/module-ai-base`):

```bash
php bin/magento module:enable MageOS_AiBase
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

Admin UI lives at **Stores → Configuration → Services → AI Configuration**.

## Architecture

There are two intentionally separate interfaces — do not conflate them:

- **`Api\Data\AiServiceConfigurationInterface`** (`getCode`, `getName`, `getConfigurationTemplate`) — describes an *available* backend: its machine code, display name, and the HTML snippet used in the admin form. Implementations live in `src/AiServices/*.php`. These are wired into the admin form by the `services` array argument on `Block\Adminhtml\Configuration\Services` in `etc/di.xml`.
- **`Api\Data\AiServiceInterface`** (`getCode`, `getConfiguration`) — represents a *configured instance* (code + stored credentials/model/etc. array). Produced at runtime by `Model\AiServiceSelector` through `AiServiceInterfaceFactory`.

`AiServiceSelectorInterface` is the public consumer API:

```php
AiServiceSelectorInterface::getAll(): AiServiceInterface[]
AiServiceSelectorInterface::getByCode(string $code): AiServiceInterface[]
```

(Note: the README example shows these methods on `AiServiceConfigurationInterface` — that's wrong; they belong to `AiServiceSelectorInterface`. `getAll` also takes no arguments.) Multiple entries per code are possible because admins can add the same backend multiple times in the UI, which is why `getByCode` returns an array.

Stored data flow:

1. Admin form is an `AbstractFieldArray` rendered via `view/adminhtml/templates/system/config/form/field/services.phtml`.
2. Each `AiServiceConfigurationInterface::getConfigurationTemplate()` returns an HTML fragment using `<%- _fieldName %>` as a `mage/template` placeholder. The phtml wires those into per-row inputs when the admin clicks one of the "Add Service" buttons.
3. Magento serializes the posted rows as JSON via `Magento\Config\Model\Config\Backend\Serialized\ArraySerialized` into `core_config_data` at path **`mageos_ai/services/configuration`**.
4. `AiServiceSelector::getParsedConfig()` reads that path, json_decodes it, and wraps each row with `AiServiceInterfaceFactory`. Each row's structure is `{ _rowId: { <service_code>: { ...fields } } }`, which is why the selector does `array_first(array_keys($item))` to extract the code.

## Adding a new AI backend

1. Create `src/AiServices/<Name>.php` implementing `AiServiceConfigurationInterface`. The configuration template's input `name` attributes must follow `<%- _fieldName %>[<service_code>][<field>]` — that nesting is what the selector expects when reading back.
2. Register it in `etc/di.xml` under the `services` argument of `Block\Adminhtml\Configuration\Services`. The array key there becomes the row identifier in the admin dropdown; it should match the class's `getCode()`.
3. No other wiring is required — the admin UI and selector pick it up automatically.

## Conventions observed in this codebase

- PHP 8 constructor property promotion + `readonly` is the norm; follow it for new classes.
- No `declare(strict_types=1)` header is used in existing files — match the surrounding style unless you're explicitly modernizing.
- `composer.json` pins `minimum-stability: dev` and `magento/framework: *` — do not tighten these without a reason.
- ACL resource: `MageOS_AiBase::configuration` (defined in `etc/acl.xml`), nested under `Magento_Backend::stores_attributes`.
