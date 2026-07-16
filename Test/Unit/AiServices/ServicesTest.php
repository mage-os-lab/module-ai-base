<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\FieldDescriptor;
use MageOS\AiBase\Model\ModelList\HttpFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
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
                encrypted: $data['encrypted'] ?? false,
            )
        );
        $this->fieldFactory = $stub;
    }

    #[DataProvider('service_classes')]
    public function test_service_exposes_required_metadata(string $className): void
    {
        $args = [$this->fieldFactory];
        if (is_subclass_of($className, ModelListProviderInterface::class)) {
            $args[] = $this->createMock(HttpFetcher::class);
        }
        /** @var AiServiceConfigurationInterface $service */
        $service = new $className(...$args);

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
            self::assertSame(
                $field->getName() === 'api_key',
                $field->isEncrypted(),
                "$className field '{$field->getName()}' must be encrypted iff it is the api_key credential"
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
            'HuggingFace'=> [\MageOS\AiBase\AiServices\HuggingFace::class],
            'LmStudio'   => [\MageOS\AiBase\AiServices\LmStudio::class],
            'Ollama'     => [\MageOS\AiBase\AiServices\Ollama::class],
            'OpenAi'     => [\MageOS\AiBase\AiServices\OpenAi::class],
            'OpenRouter' => [\MageOS\AiBase\AiServices\OpenRouter::class],
            'Xai'        => [\MageOS\AiBase\AiServices\Xai::class],
        ];
    }
}
