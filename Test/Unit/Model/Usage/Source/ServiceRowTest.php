<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage\Source;

use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Model\AiService;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\Usage\Source\ServiceRow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\Source\ServiceRow
 */
final class ServiceRowTest extends TestCase
{
    private FakeRowServiceSelector $serviceSelector;

    protected function setUp(): void
    {
        $this->serviceSelector = new FakeRowServiceSelector();
    }

    /**
     * service_id alone is an opaque JSON object key; an administrator recognises the provider
     * name, not the row key, so a registered provider's row is offered under its human name.
     */
    public function test_it_labels_a_service_row_with_its_provider_name_when_the_code_is_registered(): void
    {
        $this->serviceSelector->withServices([new AiService('_row_a', 'openai', [])]);

        $options = (new ServiceRow($this->serviceSelector, $this->registry(['openai' => 'OpenAI'])))
            ->toOptionArray();

        self::assertSame('_row_a', $options[0]['value']);
        self::assertSame('OpenAI', (string) $options[0]['label']);
    }

    /**
     * A row can outlive the module that registered its provider, so an unregistered code still
     * has to be selectable rather than vanish from the filter.
     */
    public function test_it_falls_back_to_the_raw_code_when_the_service_is_not_registered(): void
    {
        $this->serviceSelector->withServices([new AiService('_row_a', 'long_gone', [])]);

        $options = (new ServiceRow($this->serviceSelector, $this->registry([])))->toOptionArray();

        self::assertSame('long_gone', (string) $options[0]['label']);
    }

    /**
     * @param array<string,string> $names Service code => display name
     * @return ServiceRegistry
     */
    private function registry(array $names): ServiceRegistry
    {
        return new ServiceRegistry(array_map(
            static fn (string $code, string $name): AiServiceConfigurationInterface
                => new FakeRegisteredService($code, $name),
            array_keys($names),
            $names,
        ));
    }
}

/**
 * In-memory stand-in for {@see AiServiceSelectorInterface} holding a canned list of configured
 * rows, per this codebase's fakes-over-mocks convention.
 */
final class FakeRowServiceSelector implements AiServiceSelectorInterface
{
    /** @var AiServiceInterface[] */
    private array $services = [];

    /**
     * @param AiServiceInterface[] $services
     */
    public function withServices(array $services): self
    {
        $this->services = $services;

        return $this;
    }

    /**
     * @return AiServiceInterface[]
     */
    public function getAll(): array
    {
        return $this->services;
    }

    /**
     * @return AiServiceInterface[]
     */
    public function getByCode(string $code): array
    {
        return array_values(array_filter(
            $this->services,
            static fn (AiServiceInterface $service): bool => $service->getCode() === $code
        ));
    }

    public function getById(string $id): ?AiServiceInterface
    {
        foreach ($this->services as $service) {
            if ($service->getId() === $id) {
                return $service;
            }
        }

        return null;
    }
}

/**
 * A registered provider reduced to the code and display name {@see ServiceRow} reads off it.
 */
final class FakeRegisteredService implements AiServiceConfigurationInterface
{
    public function __construct(private readonly string $code, private readonly string $name)
    {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return \MageOS\AiBase\Api\Data\FieldDescriptorInterface[]
     */
    public function getConfigurationFields(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getSupportedModels(): array
    {
        return [];
    }
}
