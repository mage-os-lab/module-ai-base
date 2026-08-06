<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\AiServiceInterfaceFactory;
use MageOS\AiBase\Model\AiService;
use MageOS\AiBase\Model\AiServiceSelector;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->subject = new AiServiceSelector(
            $this->scopeConfig,
            $this->aiServiceFactory,
            new SensitiveDataProcessor($this->createMock(EncryptorInterface::class), []),
        );
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
            '_row1' => ['openai'    => ['api_key' => 'k1', 'model' => 'gpt-4o']],
            '_row2' => ['anthropic' => ['api_key' => 'k2', 'model' => 'claude-sonnet-4-6']],
        ], JSON_THROW_ON_ERROR);
        $this->scopeConfig->method('getValue')->willReturn($json);

        $this->aiServiceFactory->method('create')->willReturnCallback(
            fn (array $data) => new AiService($data['id'], $data['code'], $data['configuration'])
        );

        $result = $this->subject->getAll();

        self::assertCount(2, $result);
        self::assertContainsOnlyInstancesOf(AiServiceInterface::class, $result);
        self::assertSame('openai', $result[0]->getCode());
        self::assertSame('anthropic', $result[1]->getCode());
    }

    /**
     * The stored row key is the only thing that tells two rows of the same provider apart, so it
     * has to survive into the service object: without it a consumer module can record which
     * provider an administrator picked, but not which of that provider's rows.
     */
    public function test_get_all_exposes_the_stored_row_key_as_the_service_id(): void
    {
        $json = json_encode([
            '_1712345678901_901' => ['openai' => ['api_key' => 'k1', 'model' => 'gpt-4o']],
            '_1712345678902_902' => ['openai' => ['api_key' => 'k2', 'model' => 'o1-mini']],
        ], JSON_THROW_ON_ERROR);
        $this->scopeConfig->method('getValue')->willReturn($json);
        $this->stubFactory();

        $result = $this->subject->getAll();

        self::assertSame('_1712345678901_901', $result[0]->getId());
        self::assertSame('_1712345678902_902', $result[1]->getId());
    }

    public function test_get_all_stringifies_a_numeric_row_key(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(
            json_encode([['openai' => ['api_key' => 'k1']]], JSON_THROW_ON_ERROR)
        );
        $this->stubFactory();

        self::assertSame('0', $this->subject->getAll()[0]->getId());
    }

    public function test_get_by_id_returns_the_single_row_carrying_that_id(): void
    {
        $json = json_encode([
            '_row1' => ['openai' => ['api_key' => 'k1', 'model' => 'gpt-4o']],
            '_row2' => ['openai' => ['api_key' => 'k2', 'model' => 'o1-mini']],
        ], JSON_THROW_ON_ERROR);
        $this->scopeConfig->method('getValue')->willReturn($json);
        $this->stubFactory();

        $service = $this->subject->getById('_row2');

        self::assertInstanceOf(AiServiceInterface::class, $service);
        self::assertSame('_row2', $service->getId());
        self::assertSame('o1-mini', $service->getConfiguration()['model']);
    }

    public function test_get_by_id_returns_null_when_the_row_no_longer_exists(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(
            json_encode(['_row1' => ['openai' => ['api_key' => 'k1']]], JSON_THROW_ON_ERROR)
        );
        $this->stubFactory();

        self::assertNull($this->subject->getById('_deleted_row'));
    }

    private function stubFactory(): void
    {
        $this->aiServiceFactory->method('create')->willReturnCallback(
            fn (array $data) => new AiService($data['id'], $data['code'], $data['configuration'])
        );
    }

    /**
     * @param array<mixed> $decoded
     */
    #[DataProvider('malformed_decoded_shapes')]
    public function test_get_all_silently_skips_malformed_rows(array $decoded): void
    {
        $this->scopeConfig->method('getValue')->willReturn(json_encode($decoded, JSON_THROW_ON_ERROR));

        self::assertSame([], $this->subject->getAll());
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function malformed_decoded_shapes(): array
    {
        return [
            'row is a bare string'         => [['_row1' => 'not-an-array']],
            'row is an empty array'        => [['_row1' => []]],
            'row value is non-array'       => [['_row1' => ['openai' => 'not-an-array']]],
            'row key is integer (not code)'=> [['_row1' => [0 => ['api_key' => 'k1']]]],
        ];
    }

    public function test_get_by_code_filters_to_matching_services_only(): void
    {
        $json = json_encode([
            '_row1' => ['openai'    => ['api_key' => 'k1']],
            '_row2' => ['anthropic' => ['api_key' => 'k2']],
            '_row3' => ['openai'    => ['api_key' => 'k3']],
        ], JSON_THROW_ON_ERROR);
        $this->scopeConfig->method('getValue')->willReturn($json);

        $this->stubFactory();

        $result = $this->subject->getByCode('openai');

        self::assertCount(2, $result);
        foreach ($result as $service) {
            self::assertSame('openai', $service->getCode());
        }
    }
}
