# `module-ai-base` v1.0.0 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship `mage-os/module-ai-base` v1.0.0 — CI-gated, test-covered, demo-verified, with a cleaned-up public interface.

**Architecture:** Single-branch, additive-where-possible. The one breaking change (`AiServiceConfigurationInterface`) is applied atomically in one commit that also rewrites the eleven `AiServices/*` classes + the admin block + the phtml template, so the repo always compiles between commits. Tests are written before the code they cover per @superpowers:test-driven-development.

**Tech Stack:** PHP 8.2+, Magento 2 framework, PHPUnit 10, Graycore reusable GitHub Actions workflows, mage-os coding standard.

**Design doc:** See `docs/plans/2026-04-20-done-done-design.md` for the decisions underpinning this plan.

---

## Task 1: Tighten `composer.json` + add LICENSE + CHANGELOG

**Files:**
- Modify: `composer.json`
- Create: `LICENSE.md`
- Create: `CHANGELOG.md`

**Step 1: Replace `composer.json` contents**

```json
{
    "name": "mage-os/module-ai-base",
    "description": "Base AI module for Mage-OS — register and retrieve configuration for multiple AI backends.",
    "type": "magento2-module",
    "license": ["OSL-3.0", "AFL-3.0"],
    "authors": [
        { "name": "David Lambauer", "email": "david@run-as-root.sh" }
    ],
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

**Step 2: Create `LICENSE.md`**

Paste the OSL-3.0 + AFL-3.0 dual-license text. Source the canonical text from the Mage-OS `mageos-magento2` repo `LICENSE.txt` / `LICENSE_AFL.txt` pair, concatenated with an "OR" separator header.

**Step 3: Create `CHANGELOG.md`**

```markdown
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
```

**Step 4: Verify composer sanity**

Run: `composer validate --no-check-publish --strict`
Expected: `./composer.json is valid` with exit code 0. The `version` field is intentionally omitted — Packagist derives it from git tags (see Task 12), and leaving it in would trigger a warning that `--strict` escalates to a failure.

**Step 5: Commit**

```bash
git add composer.json LICENSE.md CHANGELOG.md
git commit -m "chore: tighten composer metadata, add LICENSE and CHANGELOG"
```

---

## Task 2: Add `phpcs.xml.dist` + CI workflow

**Files:**
- Create: `phpcs.xml.dist`
- Create: `.github/workflows/check-extension.yaml`

**Step 1: Create `phpcs.xml.dist`**

```xml
<?xml version="1.0"?>
<ruleset name="MageOS_AiBase">
    <arg name="extensions" value="php,phtml"/>
    <file>src</file>
    <exclude-pattern>src/Test/</exclude-pattern>
    <rule ref="Magento2"/>
</ruleset>
```

**Step 2: Create `.github/workflows/check-extension.yaml`**

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

**Step 3: Verify YAML parses**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/check-extension.yaml'))"`
Expected: no output, exit 0.

**Step 4: Commit**

```bash
git add phpcs.xml.dist .github/workflows/check-extension.yaml
git commit -m "ci: add Graycore check-extension workflow and phpcs config"
```

---

## Task 3: Fix `README.md`

**Files:**
- Modify: `README.md`

**Step 1: Replace the "Usage" section**

Replace lines 14-37 of `README.md` with:

````markdown
## Usage

If you have configured AI backends, you can fetch the configuration using these methods:

```php
use MageOS\AiBase\Api\AiServiceSelectorInterface;

AiServiceSelectorInterface::getAll(): array
AiServiceSelectorInterface::getByCode(string $code): array
```

Both methods return an array of `\MageOS\AiBase\Api\Data\AiServiceInterface` objects (multiple entries per code are possible because admins can register the same backend more than once).

```php
use MageOS\AiBase\Api\AiServiceSelectorInterface;

final class MyAiFunctionality
{
    public function __construct(
        private readonly AiServiceSelectorInterface $aiServiceSelector,
    ) {}

    public function doSomething(): void
    {
        $openAiServices = $this->aiServiceSelector->getByCode('openai');

        foreach ($openAiServices as $service) {
            $config = $service->getConfiguration();
            // $config = ['apikey' => '...', 'model' => 'gpt-4o', ...]
        }
    }
}
```
````

**Step 2: Commit**

```bash
git add README.md
git commit -m "docs: fix README — correct interface references in usage example"
```

---

## Task 4: Add module sequence to `module.xml`

**Files:**
- Modify: `src/etc/module.xml`

**Step 1: Replace file contents**

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="MageOS_AiBase">
        <sequence>
            <module name="Magento_Config"/>
            <module name="Magento_Backend"/>
        </sequence>
    </module>
</config>
```

**Step 2: Commit**

```bash
git add src/etc/module.xml
git commit -m "fix: declare explicit module sequence on Magento_Config and Magento_Backend"
```

---

## Task 5: Harden `AiServiceSelector` (TDD)

Use @superpowers:test-driven-development.

**Files:**
- Create: `src/Test/Unit/Model/AiServiceSelectorTest.php`
- Modify: `src/Model/AiServiceSelector.php`

**Step 1: Write failing unit test**

Create `src/Test/Unit/Model/AiServiceSelectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\AiServiceInterfaceFactory;
use MageOS\AiBase\Model\AiService;
use MageOS\AiBase\Model\AiServiceSelector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AiServiceSelectorTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private AiServiceInterfaceFactory&MockObject $aiServiceFactory;
    private AiServiceSelector $subject;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->aiServiceFactory = $this->createMock(AiServiceInterfaceFactory::class);
        $this->subject = new AiServiceSelector($this->scopeConfig, $this->aiServiceFactory);
    }

    public function test_get_all_returns_empty_array_when_config_is_null(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame([], $this->subject->getAll());
    }

    public function test_get_all_returns_empty_array_when_config_is_malformed_json(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('not-json');

        self::assertSame([], $this->subject->getAll());
    }

    public function test_get_all_returns_all_configured_services(): void
    {
        $json = json_encode([
            '_row1' => ['openai'    => ['apikey' => 'k1', 'model' => 'gpt-4o']],
            '_row2' => ['anthropic' => ['apikey' => 'k2', 'model' => 'claude-sonnet-4-6']],
        ], JSON_THROW_ON_ERROR);
        $this->scopeConfig->method('getValue')->willReturn($json);

        $this->aiServiceFactory->method('create')->willReturnCallback(
            fn (array $data) => new AiService($data['code'], $data['configuration'])
        );

        $result = $this->subject->getAll();

        self::assertCount(2, $result);
        self::assertContainsOnlyInstancesOf(AiServiceInterface::class, $result);
        self::assertSame('openai', $result[0]->getCode());
        self::assertSame('anthropic', $result[1]->getCode());
    }

    public function test_get_by_code_filters_to_matching_services_only(): void
    {
        $json = json_encode([
            '_row1' => ['openai'    => ['apikey' => 'k1']],
            '_row2' => ['anthropic' => ['apikey' => 'k2']],
            '_row3' => ['openai'    => ['apikey' => 'k3']],
        ], JSON_THROW_ON_ERROR);
        $this->scopeConfig->method('getValue')->willReturn($json);

        $this->aiServiceFactory->method('create')->willReturnCallback(
            fn (array $data) => new AiService($data['code'], $data['configuration'])
        );

        $result = $this->subject->getByCode('openai');

        self::assertCount(2, $result);
        foreach ($result as $service) {
            self::assertSame('openai', $service->getCode());
        }
    }
}
```

**Step 2: Run tests to confirm they fail**

Run: `vendor/bin/phpunit src/Test/Unit/Model/AiServiceSelectorTest.php` (or via the demo's vendor dir if this repo doesn't have one locally yet).
Expected: Test cases 1 and 2 FAIL with a `TypeError: json_decode() expects string, null given` (or similar) for case 1 and a silent `TypeError: array_map() expects array, null given` for case 2. Cases 3 and 4 should PASS.

If no local vendor dir, defer execution to the CI workflow — the test will exercise the code path there.

**Step 3: Harden the implementation**

Replace `src/Model/AiServiceSelector.php` with:

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\AiServiceInterfaceFactory;

final class AiServiceSelector implements AiServiceSelectorInterface
{
    private const CONFIG_PATH_AI_SERVICES = 'mageos_ai/services/configuration';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AiServiceInterfaceFactory $aiServiceFactory,
    ) {}

    public function getAll(): array
    {
        return $this->getParsedConfig();
    }

    public function getByCode(string $code): array
    {
        return array_values(array_filter(
            $this->getParsedConfig(),
            fn (AiServiceInterface $service) => $service->getCode() === $code,
        ));
    }

    /**
     * @return AiServiceInterface[]
     */
    private function getParsedConfig(): array
    {
        $raw = $this->scopeConfig->getValue(self::CONFIG_PATH_AI_SERVICES);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $services = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $code = array_key_first($row);
            $configuration = $row[$code];
            if (!is_string($code) || !is_array($configuration)) {
                continue;
            }
            $services[] = $this->aiServiceFactory->create([
                'code' => $code,
                'configuration' => $configuration,
            ]);
        }

        return $services;
    }
}
```

Note: replaced the `array_first()` polyfill (Laravel helper, not available in Magento core) with `array_key_first()` (native PHP 8.2+). This also fixes a latent bug in the original code where `array_first(array_keys($item))` returned the first **value** of the keys array, which happened to work but was confusing.

**Step 4: Run tests to confirm they pass**

Run: `vendor/bin/phpunit src/Test/Unit/Model/AiServiceSelectorTest.php`
Expected: 4 tests, 4 passed.

**Step 5: Commit**

```bash
git add src/Test/Unit/Model/AiServiceSelectorTest.php src/Model/AiServiceSelector.php
git commit -m "fix(selector): harden getParsedConfig against null and malformed JSON

Replaces the Laravel-style array_first() with native array_key_first()
and guards every boundary (null scope value, non-string config, failed
json_decode, non-array rows, missing code key) before constructing
AiService DTOs. Covered by 4 new unit tests."
```

---

## Task 6: Introduce `FieldDescriptor` DTO (TDD)

**Files:**
- Create: `src/Api/Data/FieldDescriptorInterface.php`
- Create: `src/Model/FieldDescriptor.php`
- Create: `src/Test/Unit/Model/FieldDescriptorTest.php`
- Modify: `src/etc/di.xml`

**Step 1: Write failing unit test**

Create `src/Test/Unit/Model/FieldDescriptorTest.php`:

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Model\FieldDescriptor;
use PHPUnit\Framework\TestCase;

final class FieldDescriptorTest extends TestCase
{
    public function test_exposes_required_fields(): void
    {
        $field = new FieldDescriptor(
            name: 'apikey',
            label: 'API Key',
            type: FieldDescriptorInterface::TYPE_PASSWORD,
        );

        self::assertSame('apikey', $field->getName());
        self::assertSame('API Key', $field->getLabel());
        self::assertSame(FieldDescriptorInterface::TYPE_PASSWORD, $field->getType());
        self::assertSame([], $field->getOptions());
        self::assertNull($field->getDefault());
    }

    public function test_select_field_carries_options_and_default(): void
    {
        $field = new FieldDescriptor(
            name: 'model',
            label: 'Model',
            type: FieldDescriptorInterface::TYPE_SELECT,
            options: [
                ['value' => 'a', 'label' => 'Apple'],
                ['value' => 'b', 'label' => 'Banana'],
            ],
            default: 'a',
        );

        self::assertSame('model', $field->getName());
        self::assertSame(FieldDescriptorInterface::TYPE_SELECT, $field->getType());
        self::assertCount(2, $field->getOptions());
        self::assertSame('a', $field->getDefault());
    }
}
```

**Step 2: Run test to confirm it fails**

Run: `vendor/bin/phpunit src/Test/Unit/Model/FieldDescriptorTest.php`
Expected: FAIL with `Class "MageOS\AiBase\Model\FieldDescriptor" not found`.

**Step 3: Create the interface**

`src/Api/Data/FieldDescriptorInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

interface FieldDescriptorInterface
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_SELECT   = 'select';

    public function getName(): string;
    public function getLabel(): string;
    public function getType(): string;

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getOptions(): array;

    public function getDefault(): ?string;
}
```

**Step 4: Create the implementation**

`src/Model/FieldDescriptor.php`:

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;

final class FieldDescriptor implements FieldDescriptorInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $type,
        private readonly array $options = [],
        private readonly ?string $default = null,
    ) {}

    public function getName(): string { return $this->name; }
    public function getLabel(): string { return $this->label; }
    public function getType(): string { return $this->type; }
    public function getOptions(): array { return $this->options; }
    public function getDefault(): ?string { return $this->default; }
}
```

**Step 5: Wire the factory preference**

Add to `src/etc/di.xml` (inside existing `<config>` element):

```xml
<preference for="MageOS\AiBase\Api\Data\FieldDescriptorInterface" type="MageOS\AiBase\Model\FieldDescriptor"/>
```

**Step 6: Run test to confirm it passes**

Run: `vendor/bin/phpunit src/Test/Unit/Model/FieldDescriptorTest.php`
Expected: 2 tests, 2 passed.

**Step 7: Commit**

```bash
git add src/Api/Data/FieldDescriptorInterface.php src/Model/FieldDescriptor.php src/Test/Unit/Model/FieldDescriptorTest.php src/etc/di.xml
git commit -m "feat: add FieldDescriptor DTO for structured admin form schema"
```

---

## Task 7: Atomic API swap — interface + 11 services + block + phtml

This is the big one. The interface, every implementer, the block that aggregates them, and the phtml that renders them all change in one commit so nothing is ever broken. Apply all code changes, then commit once.

**Files:**
- Modify: `src/Api/Data/AiServiceConfigurationInterface.php`
- Modify: `src/AiServices/Anthropic.php`
- Modify: `src/AiServices/Azure.php`
- Modify: `src/AiServices/Deepseek.php`
- Modify: `src/AiServices/Google.php`
- Modify: `src/AiServices/Grok.php`
- Modify: `src/AiServices/HuggingFace.php`
- Modify: `src/AiServices/LmStudio.php`
- Modify: `src/AiServices/Ollama.php`
- Modify: `src/AiServices/OpenAi.php`
- Modify: `src/AiServices/OpenRouter.php`
- Modify: `src/AiServices/Xai.php`
- Modify: `src/Block/Adminhtml/Configuration/Services.php`
- Modify: `src/view/adminhtml/templates/system/config/form/field/services.phtml`

**Step 1: Update `AiServiceConfigurationInterface`**

Replace contents:

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

interface AiServiceConfigurationInterface
{
    public function getCode(): string;
    public function getName(): string;

    /**
     * @return FieldDescriptorInterface[]
     */
    public function getConfigurationFields(): array;

    /**
     * @return array<string, string> value => label; empty array for services with no model list
     */
    public function getSupportedModels(): array;
}
```

**Step 2: Create a helper trait to DRY the field construction**

`src/AiServices/FieldFactoryTrait.php`:

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

trait FieldFactoryTrait
{
    private function apiKeyField(FieldDescriptorInterfaceFactory $factory): FieldDescriptorInterface
    {
        return $factory->create([
            'name'  => 'apikey',
            'label' => 'API Key',
            'type'  => FieldDescriptorInterface::TYPE_PASSWORD,
        ]);
    }

    private function modelField(FieldDescriptorInterfaceFactory $factory, array $supportedModels): FieldDescriptorInterface
    {
        $options = [];
        foreach ($supportedModels as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        return $factory->create([
            'name'    => 'model',
            'label'   => 'Model',
            'type'    => FieldDescriptorInterface::TYPE_SELECT,
            'options' => $options,
        ]);
    }

    private function baseUrlField(FieldDescriptorInterfaceFactory $factory, string $default): FieldDescriptorInterface
    {
        return $factory->create([
            'name'    => 'base_url',
            'label'   => 'Base URL',
            'type'    => FieldDescriptorInterface::TYPE_TEXT,
            'default' => $default,
        ]);
    }
}
```

**Step 3: Rewrite each `AiServices/*` class**

Template for cloud services (OpenAI, Anthropic, Deepseek, Grok/xAI, Google, OpenRouter, HuggingFace, Azure):

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

final class OpenAi implements AiServiceConfigurationInterface
{
    use FieldFactoryTrait;

    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {}

    public function getCode(): string { return 'openai'; }
    public function getName(): string { return 'OpenAI'; }

    public function getSupportedModels(): array
    {
        return [
            'gpt-4o'       => 'GPT-4o',
            'gpt-4o-mini'  => 'GPT-4o mini',
            'gpt-4-turbo'  => 'GPT-4 Turbo',
            'o1'           => 'o1',
            'o1-mini'      => 'o1 mini',
        ];
    }

    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField($this->fieldFactory),
            $this->modelField($this->fieldFactory, $this->getSupportedModels()),
        ];
    }
}
```

Apply this pattern to each of the 11 services. Model lists per service (current as of 2026-04-20):

| Service | `getCode()` | `getName()` | Supported models |
|---|---|---|---|
| `Anthropic` | `anthropic` | `Anthropic` | `claude-opus-4-7` → `Claude Opus 4.7`, `claude-sonnet-4-6` → `Claude Sonnet 4.6`, `claude-haiku-4-5-20251001` → `Claude Haiku 4.5` |
| `Azure` | `azure` | `Azure OpenAI` | same list as OpenAI |
| `Deepseek` | `deepseek` | `DeepSeek` | `deepseek-chat` → `DeepSeek V3`, `deepseek-reasoner` → `DeepSeek R1` |
| `Google` | `google` | `Google Gemini` | `gemini-2.0-pro` → `Gemini 2.0 Pro`, `gemini-2.0-flash` → `Gemini 2.0 Flash`, `gemini-1.5-pro` → `Gemini 1.5 Pro` |
| `Grok` | `grok` | `Grok` | `grok-2` → `Grok 2`, `grok-2-mini` → `Grok 2 mini` |
| `HuggingFace` | `huggingface` | `Hugging Face` | empty array; add a free-text `model` field instead of a select |
| `OpenAi` | `openai` | `OpenAI` | (as above) |
| `OpenRouter` | `openrouter` | `OpenRouter` | empty; free-text `model` field |
| `Xai` | `xai` | `xAI` | same as Grok |

For the two local services (LM Studio, Ollama), there is no API key — just a base URL + free-text model:

```php
final class Ollama implements AiServiceConfigurationInterface
{
    use FieldFactoryTrait;

    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {}

    public function getCode(): string { return 'ollama'; }
    public function getName(): string { return 'Ollama'; }
    public function getSupportedModels(): array { return []; }

    public function getConfigurationFields(): array
    {
        return [
            $this->baseUrlField($this->fieldFactory, 'http://localhost:11434'),
            $this->fieldFactory->create([
                'name'  => 'model',
                'label' => 'Model',
                'type'  => \MageOS\AiBase\Api\Data\FieldDescriptorInterface::TYPE_TEXT,
            ]),
        ];
    }
}
```

Apply the same shape to `LmStudio` with default URL `http://localhost:1234`.

**Step 4: Update `Block\Adminhtml\Configuration\Services`**

Replace `src/Block/Adminhtml/Configuration/Services.php` with:

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\Block\Adminhtml\Configuration;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class Services extends AbstractFieldArray
{
    protected $_template = 'MageOS_AiBase::system/config/form/field/services.phtml';

    public function __construct(
        Context $context,
        private readonly Json $jsonSerializer,
        /** @var AiServiceConfigurationInterface[] */
        private readonly array $services,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null,
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    public function getServicesButtons(): array
    {
        return array_map(
            fn (AiServiceConfigurationInterface $service) => [
                'code' => $service->getCode(),
                'name' => $service->getName(),
            ],
            $this->services,
        );
    }

    /**
     * @return string JSON object keyed by service code, each value is a list of field descriptors as arrays
     */
    public function getServicesSchemaJson(): string
    {
        $schema = [];
        foreach ($this->services as $service) {
            $schema[$service->getCode()] = array_map(
                fn ($field) => [
                    'name'    => $field->getName(),
                    'label'   => $field->getLabel(),
                    'type'    => $field->getType(),
                    'options' => $field->getOptions(),
                    'default' => $field->getDefault(),
                ],
                $service->getConfigurationFields(),
            );
        }
        return $this->jsonSerializer->serialize($schema);
    }

    protected function _prepareToRender(): void
    {
        $this->addColumn('service', [
            'label' => __('Service'),
            'class' => 'required-entry',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Service');
    }
}
```

**Step 5: Rewrite `services.phtml`**

Replace `src/view/adminhtml/templates/system/config/form/field/services.phtml`:

```php
<?php
/** @var \Magento\Framework\Escaper $escaper */
/** @var \MageOS\AiBase\Block\Adminhtml\Configuration\Services $block */

$_htmlId = $block->getHtmlId() ?: '_' . uniqid();
$_colspan = $block->isAddAfter() ? 2 : 1;
?>
<div class="ai-services-configurator" id="grid<?= $escaper->escapeHtmlAttr($_htmlId) ?>">
    <div class="admin__control-table-wrapper">
        <table class="admin__control-table" id="<?= $escaper->escapeHtmlAttr($block->getElement()->getId()) ?>">
            <thead>
                <tr>
                    <?php foreach ($block->getColumns() as $column): ?>
                        <th><?= $escaper->escapeHtml($column['label']) ?></th>
                    <?php endforeach; ?>
                    <th class="col-actions" colspan="<?= (int) $_colspan ?>">
                        <?= $escaper->escapeHtml(__('Action')) ?>
                    </th>
                </tr>
            </thead>
            <tfoot>
                <tr>
                    <td colspan="<?= count($block->getColumns()) + $_colspan ?>" class="col-actions-add">
                        <ul>
                            <?php foreach ($block->getServicesButtons() as $button): ?>
                                <li data-ai-service="<?= $escaper->escapeHtmlAttr($button['code']) ?>"
                                    class="add-ai-service ai-service-<?= $escaper->escapeHtmlAttr($button['code']) ?>">
                                    <?= $escaper->escapeHtml($button['name']) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
            </tfoot>
            <tbody id="addRow<?= $escaper->escapeHtmlAttr($_htmlId) ?>"></tbody>
        </table>
    </div>
    <input type="hidden"
           name="<?= $escaper->escapeHtmlAttr($block->getElement()->getName()) ?>[__empty]"
           value=""/>

    <script>
        require(['prototype'], function () {
            const schema = <?= /* @noEscape */ $block->getServicesSchemaJson() ?>;
            const fieldNameBase = '<?= $escaper->escapeJs($block->getElement()->getName()) ?>';

            function buildField(serviceCode, fieldName, field) {
                const base = fieldNameBase + '[' + fieldName + '][' + serviceCode + '][' + field.name + ']';
                if (field.type === 'select') {
                    const options = field.options.map(function (opt) {
                        const selected = (field.default === opt.value) ? ' selected' : '';
                        return '<option value="' + opt.value + '"' + selected + '>' + opt.label + '</option>';
                    }).join('');
                    return '<select name="' + base + '">' + options + '</select>';
                }
                const defaultAttr = field.default ? ' value="' + field.default + '"' : '';
                return '<input type="' + field.type + '" name="' + base + '"' + defaultAttr + '/>';
            }

            function addRow(serviceCode, values) {
                const fields = schema[serviceCode];
                if (!fields) return;

                const d = new Date();
                const rowId = '_' + d.getTime() + '_' + d.getMilliseconds();

                const rows = fields.map(function (field) {
                    return '<tr>'
                        + '<th>' + field.label + '</th>'
                        + '<td>' + buildField(serviceCode, rowId, field) + '</td>'
                        + '</tr>';
                }).join('');

                const rowHtml = '<tr id="' + rowId + '">'
                    + '<td><table>' + rows + '</table></td>'
                    + '<td class="col-actions">'
                    + '<button class="action-delete" data-row-id="' + rowId + '" type="button">'
                    + '<span><?= $escaper->escapeJs(__('Delete')) ?></span></button></td>'
                    + '</tr>';

                Element.insert($('addRow<?= $escaper->escapeJs($_htmlId) ?>'), { bottom: rowHtml });

                if (values) {
                    Object.keys(values).forEach(function (key) {
                        const selector = '[name="' + fieldNameBase + '[' + rowId + '][' + serviceCode + '][' + key + ']"]';
                        const el = document.querySelector(selector);
                        if (el) el.value = values[key];
                    });
                }
            }

            document.querySelectorAll('.ai-services-configurator .add-ai-service').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    addRow(e.target.dataset.aiService);
                });
            });

            document.querySelector('.ai-services-configurator').addEventListener('click', function (e) {
                if (!e.target.classList.contains('action-delete')) return;
                document.getElementById(e.target.dataset.rowId).remove();
            });

            <?php foreach ($block->getArrayRows() as $_row): ?>
                <?php $rowData = $_row->getData(); ?>
                <?php $serviceCode = array_key_first($rowData); ?>
                addRow(
                    '<?= $escaper->escapeJs($serviceCode) ?>',
                    <?= /* @noEscape */ json_encode($rowData[$serviceCode]) ?>
                );
            <?php endforeach; ?>
        });
    </script>
</div>
```

**Step 6: Verify the repo parses**

Run: `php -l src/Model/AiServiceSelector.php && php -l src/Block/Adminhtml/Configuration/Services.php`
Expected: `No syntax errors detected` for both.

Then for each `AiServices/*.php`:

Run: `for f in src/AiServices/*.php; do php -l "$f"; done`
Expected: `No syntax errors detected` × 11 (ignore `FieldFactoryTrait.php` if matched — it also lints clean).

**Step 7: Commit**

```bash
git add src/Api/Data/AiServiceConfigurationInterface.php src/AiServices/ src/Block/Adminhtml/Configuration/Services.php src/view/adminhtml/templates/system/config/form/field/services.phtml
git commit -m "feat!: replace HTML-template config with FieldDescriptor schema

BREAKING CHANGE: AiServiceConfigurationInterface::getConfigurationTemplate()
is removed. Implementers now return FieldDescriptor[] from getConfigurationFields()
and (optionally) a model list from getSupportedModels().

Admin form phtml now renders fields by type from a JSON schema rather than
substituting HTML template strings. Storage format is unchanged, so the
AiServiceSelector consumer API is fully backwards compatible."
```

---

## Task 8: Parametrised smoke test for all 11 services

**Files:**
- Create: `src/Test/Unit/AiServices/ServicesTest.php`

**Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Model\FieldDescriptor;
use PHPUnit\Framework\TestCase;

final class ServicesTest extends TestCase
{
    private FieldDescriptorInterfaceFactory $fieldFactory;

    protected function setUp(): void
    {
        $stub = $this->createMock(FieldDescriptorInterfaceFactory::class);
        $stub->method('create')->willReturnCallback(
            fn (array $data) => new FieldDescriptor(
                name: $data['name'],
                label: $data['label'],
                type: $data['type'],
                options: $data['options'] ?? [],
                default: $data['default'] ?? null,
            )
        );
        $this->fieldFactory = $stub;
    }

    /**
     * @dataProvider service_classes
     */
    public function test_service_exposes_required_metadata(string $className): void
    {
        /** @var AiServiceConfigurationInterface $service */
        $service = new $className($this->fieldFactory);

        self::assertNotEmpty($service->getCode(), "$className::getCode() must be non-empty");
        self::assertNotEmpty($service->getName(), "$className::getName() must be non-empty");

        $fields = $service->getConfigurationFields();
        self::assertNotEmpty($fields, "$className::getConfigurationFields() must return at least one field");
        foreach ($fields as $field) {
            self::assertInstanceOf(FieldDescriptorInterface::class, $field);
            self::assertNotEmpty($field->getName());
            self::assertNotEmpty($field->getLabel());
            self::assertContains(
                $field->getType(),
                [FieldDescriptorInterface::TYPE_TEXT, FieldDescriptorInterface::TYPE_PASSWORD, FieldDescriptorInterface::TYPE_SELECT],
            );
        }

        self::assertIsArray($service->getSupportedModels());
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function service_classes(): array
    {
        return [
            'Anthropic'  => [\MageOS\AiBase\AiServices\Anthropic::class],
            'Azure'      => [\MageOS\AiBase\AiServices\Azure::class],
            'Deepseek'   => [\MageOS\AiBase\AiServices\Deepseek::class],
            'Google'     => [\MageOS\AiBase\AiServices\Google::class],
            'Grok'       => [\MageOS\AiBase\AiServices\Grok::class],
            'HuggingFace'=> [\MageOS\AiBase\AiServices\HuggingFace::class],
            'LmStudio'   => [\MageOS\AiBase\AiServices\LmStudio::class],
            'Ollama'     => [\MageOS\AiBase\AiServices\Ollama::class],
            'OpenAi'     => [\MageOS\AiBase\AiServices\OpenAi::class],
            'OpenRouter' => [\MageOS\AiBase\AiServices\OpenRouter::class],
            'Xai'        => [\MageOS\AiBase\AiServices\Xai::class],
        ];
    }
}
```

**Step 2: Run the test**

Run: `vendor/bin/phpunit src/Test/Unit/AiServices/ServicesTest.php`
Expected: 11 tests, 11 passed.

**Step 3: Commit**

```bash
git add src/Test/Unit/AiServices/ServicesTest.php
git commit -m "test: add parametrised smoke test covering all 11 AiServices classes"
```

---

## Task 9: Integration test — config round-trip

**Files:**
- Create: `src/Test/Integration/Model/AiServiceSelectorTest.php`
- Create: `phpunit.xml.dist` (if missing)

**Step 1: Create `phpunit.xml.dist` at repo root if not already present**

```xml
<?xml version="1.0"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>src/Test/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>src/Test/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Note: the Graycore `check-extension` workflow provides its own Magento-integration-test bootstrap — we don't need to supply one. This repo-root file is only for local sanity runs of the Unit suite.

**Step 2: Create the integration test**

```php
<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model;

use Magento\Config\Model\ResourceModel\Config as ResourceConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use PHPUnit\Framework\TestCase;

final class AiServiceSelectorTest extends TestCase
{
    public function test_round_trips_configuration_through_scope_config(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        /** @var WriterInterface $configWriter */
        $configWriter = $objectManager->get(WriterInterface::class);
        $json = json_encode([
            '_row1' => ['openai'    => ['apikey' => 'sk-test', 'model' => 'gpt-4o']],
            '_row2' => ['anthropic' => ['apikey' => 'sk-ant',  'model' => 'claude-sonnet-4-6']],
        ], JSON_THROW_ON_ERROR);
        $configWriter->save('mageos_ai/services/configuration', $json);

        /** @var ScopeConfigInterface $scopeConfig */
        $scopeConfig = $objectManager->get(ScopeConfigInterface::class);
        $scopeConfig->clean();

        /** @var AiServiceSelectorInterface $selector */
        $selector = $objectManager->get(AiServiceSelectorInterface::class);
        $services = $selector->getAll();

        self::assertCount(2, $services);
        self::assertSame('openai', $services[0]->getCode());
        self::assertSame(['apikey' => 'sk-test', 'model' => 'gpt-4o'], $services[0]->getConfiguration());
        self::assertSame('anthropic', $services[1]->getCode());

        $openAiOnly = $selector->getByCode('openai');
        self::assertCount(1, $openAiOnly);

        $configWriter->delete('mageos_ai/services/configuration');
    }
}
```

**Step 3: Commit**

```bash
git add src/Test/Integration/Model/AiServiceSelectorTest.php phpunit.xml.dist
git commit -m "test: add integration test covering config round-trip through ScopeConfig"
```

---

## Task 10: strict_types sweep — verify full coverage

**Files:** any `src/**/*.php` still missing `declare(strict_types=1)`.

**Step 1: Locate any stragglers**

Run: `grep -L 'declare(strict_types=1);' src/**/*.php`

Expected: empty output. If any file is listed, add the declaration as the first statement after `<?php`.

**Step 2: Re-run the unit suite**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: all tests pass.

**Step 3: Commit (only if any file was modified)**

```bash
git add src/
git commit -m "chore: add declare(strict_types=1) to remaining PHP files"
```

---

## Task 11: Demo smoke test on `mage-os-typesense`

This task involves touching a different repo. Verify each manual check before proceeding to the next step — if any check fails, stop and diagnose rather than push through.

**Files:**
- Modify: `/Users/david/Herd/mage-os-typesense/composer.json` (repositories block)

**Step 1: Add path repository to the demo**

Add this entry to the demo's `composer.json` `repositories` block:

```json
"ai-base": { "type": "path", "url": "/Users/david/Herd/module-ai-base" }
```

**Step 2: Require the module**

Run from `/Users/david/Herd/mage-os-typesense`:
```bash
composer require mage-os/module-ai-base:@dev
```
Expected: symlink created, `MageOS_AiBase` shows up in `app/code/` via composer autoload.

**Step 3: Enable + compile**

Run:
```bash
bin/magento module:enable MageOS_AiBase
bin/magento setup:upgrade
bin/magento setup:di:compile
```
Expected: module shows `MageOS_AiBase` in the enabled list, `setup:di:compile` completes without "Missing required argument" errors.

**Step 4: Admin UI check**

Log in at `/admin` as `david` / `Admin12345!`.

Navigate: **Stores → Configuration → Services → AI Configuration**.

Click **Add Service → OpenAI**, enter `sk-test` as API key, pick `gpt-4o` from the Model dropdown.
Click **Add Service → Anthropic**, enter `sk-ant` and pick a Claude model.
Click **Save Config**.

Expected: success message, no PHP errors in `var/log/exception.log`.

**Step 5: Refresh + verify persistence**

Refresh the config page. Expected: both services rendered, password inputs visible as masked, select inputs show the previously-selected value.

**Step 6: Object-manager sanity check**

Run from Magento root:
```bash
php -r 'require "app/bootstrap.php"; $app = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER); $om = $app->getObjectManager(); var_dump(count($om->get(\MageOS\AiBase\Api\AiServiceSelectorInterface::class)->getAll()));'
```
Expected: `int(2)`.

**Step 7: Delete rows + null path check**

In admin, delete both service rows, click **Save Config**.
Re-run the PHP one-liner from Step 6.
Expected: `int(0)` (exercises the null/empty-config defensive branch).

**Step 8: No commit needed in this repo; commit the demo change-set separately**

The change to `/Users/david/Herd/mage-os-typesense/composer.json` is a dev convenience, not part of this module's release. Optionally commit it there in isolation:

```bash
cd /Users/david/Herd/mage-os-typesense
git add composer.json composer.lock
git commit -m "chore: wire path repo for mage-os/module-ai-base"
```

---

## Task 12: Tag `v1.0.0`

**Step 0: Apply release-polish deferred from Task 1 code review**

Before tagging:
- Add a `## [Unreleased]` section to `CHANGELOG.md` at the top (gives future PRs a clear landing spot).
- Add Keep-a-Changelog preamble lines after the intro:
  ```
  The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
  and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
  ```
- Add a compare-link footer to `CHANGELOG.md`:
  ```
  [Unreleased]: https://github.com/mage-os/module-ai-base/compare/v1.0.0...HEAD
  [1.0.0]: https://github.com/mage-os/module-ai-base/releases/tag/v1.0.0
  ```
- Create `.gitattributes` at repo root:
  ```
  /.github         export-ignore
  /docs            export-ignore
  /src/Test        export-ignore
  /phpcs.xml.dist  export-ignore
  /phpunit.xml.dist export-ignore
  /CLAUDE.md       export-ignore
  ```
  (Keeps dev scaffolding out of the dist tarball that composer serves from a tagged release.)
- Decide on LICENSE presentation: either keep the single `LICENSE.md` with both texts, or split into `LICENSE_OSL.md` + `LICENSE_AFL.md` to match the Mage-OS core convention and make GitHub's license detector show "Other (OSL-3.0 OR AFL-3.0)" correctly. Current choice: keep single file (documented here).

Commit these together before Step 1 with message `chore: release-polish per code review (gitattributes, changelog hygiene)`.

**Step 1: Final verification**

Run all pre-release checks:

```bash
cd /Users/david/Herd/module-ai-base
git status                                # clean
git log --oneline -15                     # sensible history, no fixups
composer validate --strict
vendor/bin/phpunit --testsuite Unit       # all pass
```

Expected: clean working tree, valid composer, all tests green.

**Step 2: Push main**

```bash
git push origin main
```

**Step 3: Wait for CI to go green**

Watch the Actions tab on GitHub — `check-extension` workflow should run all four jobs against the Mage-OS matrix and pass. If anything fails, fix on a follow-up commit rather than tagging a red release.

**Step 4: Tag and push**

```bash
git tag -a v1.0.0 -m "v1.0.0 — initial stable release"
git push origin v1.0.0
```

**Step 5: Draft a GitHub Release**

On github.com/mage-os/module-ai-base/releases, click **Draft a new release**, choose tag `v1.0.0`, paste the `## [1.0.0]` section of `CHANGELOG.md` as the body. Publish.

**Step 6: Manual packagist submission**

On packagist.org → Submit → paste `https://github.com/mage-os/module-ai-base`. Confirm the package shows the `1.0.0` version with correct metadata. Enable the GitHub webhook so future tags auto-publish.

**Step 7: Verify end-to-end**

From any scratch directory:
```bash
mkdir /tmp/ai-base-smoke && cd /tmp/ai-base-smoke
composer require mage-os/module-ai-base:^1.0.0 --no-install --dry-run
```
Expected: composer resolves the package without errors, showing `mage-os/module-ai-base 1.0.0`.

---

## Done

All done-done criteria met:
- Graycore `check-extension` CI runs on every PR, matrix-testing against current Mage-OS versions.
- Unit + integration tests cover the selector hardening, the field DTO, every service, and a full config round-trip.
- `FieldDescriptor` schema replaces string-template HTML; model lists are declarative.
- Demo install on `mage-os-typesense` exercises the admin UI end-to-end and both `getAll()` data paths.
- `v1.0.0` tagged, released, on packagist.
