<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Block\Adminhtml\Configuration;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Block\Adminhtml\Configuration\Services;
use MageOS\AiBase\Model\FieldDescriptor;
use MageOS\AiBase\Model\ModelList\Resolver;
use MageOS\AiBase\Model\ServiceRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The schema JSON drives the whole admin form, including which model options the
 * refreshed list feeds into a row.
 *
 * A resolved model list must reach the model field whatever its type. Before this was
 * fixed the substitution required TYPE_SELECT, so for providers whose model field is
 * free text (self-hosted Ollama and LM Studio, and OpenRouter) Refresh Models fetched
 * the list, persisted it, flushed the config cache and reported success while the form
 * showed nothing.
 */
final class ServicesSchemaTest extends TestCase
{
    private Resolver&MockObject $modelListResolver;

    protected function setUp(): void
    {
        if (!class_exists(AbstractFieldArray::class)) {
            self::markTestSkipped('magento/module-config is not installed in this environment.');
        }

        $this->modelListResolver = $this->createMock(Resolver::class);
    }

    public function test_resolved_models_reach_a_free_text_model_field(): void
    {
        $schema = $this->schemaFor(
            $this->service('ollama', [$this->field('model', FieldDescriptorInterface::TYPE_TEXT)]),
            ['llama3' => 'Llama 3', 'mistral' => 'Mistral']
        );

        self::assertSame(
            [
                ['value' => 'llama3', 'label' => 'Llama 3'],
                ['value' => 'mistral', 'label' => 'Mistral'],
            ],
            $schema['ollama']['fields'][0]['options'],
            'A free-text model field must still receive the refreshed list, as datalist suggestions.'
        );
    }

    public function test_resolved_models_still_reach_a_model_select(): void
    {
        $schema = $this->schemaFor(
            $this->service('openai', [$this->field('model', FieldDescriptorInterface::TYPE_SELECT)]),
            ['gpt-4o' => 'GPT-4o']
        );

        self::assertSame(
            [['value' => 'gpt-4o', 'label' => 'GPT-4o']],
            $schema['openai']['fields'][0]['options']
        );
    }

    public function test_fields_other_than_model_keep_their_own_options(): void
    {
        $ownOptions = [['value' => 'a', 'label' => 'A']];
        $schema = $this->schemaFor(
            $this->service('openai', [
                $this->field('region', FieldDescriptorInterface::TYPE_SELECT, $ownOptions),
            ]),
            ['gpt-4o' => 'GPT-4o']
        );

        self::assertSame($ownOptions, $schema['openai']['fields'][0]['options']);
    }

    public function test_model_field_falls_back_to_its_own_options_when_no_models_resolve(): void
    {
        $schema = $this->schemaFor(
            $this->service('ollama', [$this->field('model', FieldDescriptorInterface::TYPE_TEXT)]),
            []
        );

        self::assertSame([], $schema['ollama']['fields'][0]['options']);
    }

    /**
     * @param array<int, FieldDescriptorInterface> $fields
     */
    private function service(string $code, array $fields): AiServiceConfigurationInterface
    {
        return new class ($code, $fields) implements AiServiceConfigurationInterface {
            /**
             * @param array<int, FieldDescriptorInterface> $fields
             */
            public function __construct(private readonly string $code, private readonly array $fields)
            {
            }

            /**
             * @inheritdoc
             */
            public function getCode(): string
            {
                return $this->code;
            }

            /**
             * @inheritdoc
             */
            public function getName(): string
            {
                return ucfirst($this->code);
            }

            /**
             * @inheritdoc
             */
            public function getConfigurationFields(): array
            {
                return $this->fields;
            }

            /**
             * @inheritdoc
             */
            public function getSupportedModels(): array
            {
                return [];
            }
        };
    }

    /**
     * @param array<int, array{value: string, label: string}> $options
     */
    private function field(string $name, string $type, array $options = []): FieldDescriptorInterface
    {
        return new FieldDescriptor($name, ucfirst($name), $type, $options);
    }

    /**
     * @param array<string, string> $models
     * @return array<string, array{fields: array, supportsModelRefresh: bool}>
     */
    private function schemaFor(AiServiceConfigurationInterface $service, array $models): array
    {
        $this->modelListResolver->method('getModels')->willReturn($models);

        return (new Json())->unserialize($this->blockWith($service)->getServicesSchemaJson());
    }

    /**
     * Build the block without running its constructor.
     *
     * `Magento\Backend\Block\Template::__construct()` resolves its `jsonHelper` through
     * `ObjectManager::getInstance()`, two levels above this class, so no constructor
     * argument can avoid it and the real ObjectManager is not available in a unit test.
     * `getServicesSchemaJson()` depends only on the three properties set here.
     */
    private function blockWith(AiServiceConfigurationInterface $service): Services
    {
        $reflection = new \ReflectionClass(Services::class);
        $block = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('jsonSerializer')->setValue($block, new Json());
        $reflection->getProperty('serviceRegistry')->setValue($block, new ServiceRegistry([$service]));
        $reflection->getProperty('modelListResolver')->setValue($block, $this->modelListResolver);

        return $block;
    }
}
