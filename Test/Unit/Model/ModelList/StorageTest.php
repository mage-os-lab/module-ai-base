<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\ModelList;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Model\ModelList\Storage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\ModelList\Storage
 */
final class StorageTest extends TestCase
{
    private WriterInterface&MockObject $configWriter;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private TypeListInterface&MockObject $cacheTypeList;
    private Storage $subject;

    protected function setUp(): void
    {
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);

        $this->subject = new Storage(
            $this->configWriter,
            $this->scopeConfig,
            new Json(),
            $this->cacheTypeList,
        );
    }

    public function test_save_writes_json_payload_with_timestamp_and_cleans_config_cache(): void
    {
        $written = null;
        $this->configWriter->expects(self::once())->method('save')
            ->willReturnCallback(function (string $path, $value) use (&$written) {
                self::assertSame('mageos_ai/services/models/openai', $path);
                $written = $value;
            });
        $this->cacheTypeList->expects(self::once())->method('cleanType')->with('config');

        $before = time();
        $this->subject->save('openai', ['gpt-4o' => 'GPT-4o']);

        self::assertIsString($written);
        $payload = json_decode($written, true);
        self::assertSame(['gpt-4o' => 'GPT-4o'], $payload['models']);
        self::assertGreaterThanOrEqual($before, $payload['fetched_at']);
        self::assertLessThanOrEqual(time(), $payload['fetched_at']);
    }

    public function test_get_models_decodes_stored_payload(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('mageos_ai/services/models/openai')
            ->willReturn('{"fetched_at":1750000000,"models":{"gpt-4o":"GPT-4o","o1":"o1"}}');

        self::assertSame(['gpt-4o' => 'GPT-4o', 'o1' => 'o1'], $this->subject->getModels('openai'));
        self::assertSame(1750000000, $this->subject->getFetchedAt('openai'));
    }

    public function test_get_models_returns_null_when_nothing_stored(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertNull($this->subject->getModels('openai'));
        self::assertNull($this->subject->getFetchedAt('openai'));
    }

    public function test_get_models_returns_null_on_malformed_payload(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('{not json');

        self::assertNull($this->subject->getModels('openai'));
        self::assertNull($this->subject->getFetchedAt('openai'));
    }
}
