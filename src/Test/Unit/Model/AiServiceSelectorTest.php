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
