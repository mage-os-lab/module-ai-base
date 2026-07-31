<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\ModelList;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Model\ModelList\Resolver;
use MageOS\AiBase\Model\ModelList\Storage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\ModelList\Resolver
 */
final class ResolverTest extends TestCase
{
    private Storage&MockObject $storage;
    private Resolver $subject;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(Storage::class);
        $this->subject = new Resolver($this->storage);
    }

    public function test_stored_list_wins_over_curated_defaults(): void
    {
        $this->storage->method('getModels')->with('openai')
            ->willReturn(['gpt-5' => 'GPT-5']);

        $service = $this->serviceMock('openai', ['gpt-4o' => 'GPT-4o']);

        self::assertSame(['gpt-5' => 'GPT-5'], $this->subject->getModels($service));
    }

    public function test_falls_back_to_supported_models_when_nothing_stored(): void
    {
        $this->storage->method('getModels')->with('openai')->willReturn(null);

        $service = $this->serviceMock('openai', ['gpt-4o' => 'GPT-4o']);

        self::assertSame(['gpt-4o' => 'GPT-4o'], $this->subject->getModels($service));
    }

    public function test_falls_back_to_supported_models_when_stored_list_is_empty(): void
    {
        $this->storage->method('getModels')->with('openai')->willReturn([]);

        $service = $this->serviceMock('openai', ['gpt-4o' => 'GPT-4o']);

        self::assertSame(['gpt-4o' => 'GPT-4o'], $this->subject->getModels($service));
    }

    /**
     * Build a service configuration stub with the given code and curated model list.
     *
     * @param string $code
     * @param array<string, string> $supportedModels
     * @return AiServiceConfigurationInterface&MockObject
     */
    private function serviceMock(string $code, array $supportedModels): AiServiceConfigurationInterface&MockObject
    {
        $service = $this->createMock(AiServiceConfigurationInterface::class);
        $service->method('getCode')->willReturn($code);
        $service->method('getSupportedModels')->willReturn($supportedModels);

        return $service;
    }
}
