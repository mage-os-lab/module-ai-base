<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Model\Config\Backend\EncryptedServices;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use MageOS\AiBase\Model\FieldDescriptor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EncryptedServicesTest extends TestCase
{
    private const CONFIG_PATH = 'mageos_ai/services/configuration';

    private ScopeConfigInterface&MockObject $scopeConfig;
    private Json $serializer;
    private EncryptedServices $subject;

    protected function setUp(): void
    {
        if (!class_exists(ArraySerialized::class)) {
            self::markTestSkipped('magento/module-config is not installed in this environment.');
        }

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(fn (string $v) => '0:3:enc(' . $v . ')');
        $encryptor->method('decrypt')->willReturnCallback(
            fn (string $v) => preg_replace('/^0:3:enc\((.*)\)$/', '$1', $v)
        );

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->serializer = new Json();

        $this->subject = (new ObjectManager($this))->getObject(EncryptedServices::class, [
            'config' => $this->scopeConfig,
            'sensitiveDataProcessor' => new SensitiveDataProcessor($encryptor, [$this->createOpenAiFake()]),
            'jsonSerializer' => $this->serializer,
        ]);
        $this->subject->setPath(self::CONFIG_PATH);
    }

    public function test_after_load_masks_credentials_instead_of_decrypting(): void
    {
        $this->subject->setValue($this->serializer->serialize([
            '_row1' => ['openai' => ['api_key' => '0:3:enc(secret-key)', 'model' => 'gpt-4o']],
        ]));

        $this->subject->afterLoad();

        self::assertSame(
            ['_row1' => ['openai' => [
                'api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
                'model' => 'gpt-4o',
            ]]],
            $this->subject->getValue()
        );
    }

    public function test_before_save_restores_stored_credential_for_placeholder(): void
    {
        $this->givenStoredConfig([
            '_row1' => ['openai' => ['api_key' => '0:3:enc(secret-key)', 'model' => 'gpt-4o']],
        ]);
        $this->subject->setValue([
            '_row1' => ['openai' => [
                'api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
                'model' => 'gpt-4.1',
            ]],
            '__empty' => '',
        ]);

        $this->subject->beforeSave();

        self::assertSame(
            ['_row1' => ['openai' => ['api_key' => '0:3:enc(secret-key)', 'model' => 'gpt-4.1']]],
            $this->serializer->unserialize((string)$this->subject->getValue())
        );
    }

    public function test_before_save_encrypts_newly_entered_credential(): void
    {
        $this->givenStoredConfig([
            '_row1' => ['openai' => ['api_key' => '0:3:enc(old-key)', 'model' => 'gpt-4o']],
        ]);
        $this->subject->setValue([
            '_row1' => ['openai' => ['api_key' => 'new-key', 'model' => 'gpt-4o']],
        ]);

        $this->subject->beforeSave();

        self::assertSame(
            ['_row1' => ['openai' => ['api_key' => '0:3:enc(new-key)', 'model' => 'gpt-4o']]],
            $this->serializer->unserialize((string)$this->subject->getValue())
        );
    }

    public function test_before_save_blanks_placeholder_for_unknown_row(): void
    {
        $this->givenStoredConfig([]);
        $this->subject->setValue([
            '_rowNew' => ['openai' => [
                'api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
                'model' => 'gpt-4o',
            ]],
        ]);

        $this->subject->beforeSave();

        self::assertSame(
            ['_rowNew' => ['openai' => ['api_key' => '', 'model' => 'gpt-4o']]],
            $this->serializer->unserialize((string)$this->subject->getValue())
        );
    }

    /**
     * Build a fake "openai" provider whose schema marks api_key as encrypted,
     * so the backend model resolves sensitivity through the schema path.
     *
     * @return AiServiceConfigurationInterface
     */
    private function createOpenAiFake(): AiServiceConfigurationInterface
    {
        return new class implements AiServiceConfigurationInterface {
            /**
             * @inheritdoc
             */
            public function getCode(): string
            {
                return 'openai';
            }

            /**
             * @inheritdoc
             */
            public function getName(): string
            {
                return 'OpenAI (fake)';
            }

            /**
             * @inheritdoc
             */
            public function getConfigurationFields(): array
            {
                return [
                    new FieldDescriptor(
                        name: 'api_key',
                        label: 'API Key',
                        type: FieldDescriptorInterface::TYPE_PASSWORD,
                        encrypted: true,
                    ),
                    new FieldDescriptor(
                        name: 'model',
                        label: 'Model',
                        type: FieldDescriptorInterface::TYPE_TEXT,
                    ),
                ];
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
     * Stub the previously stored configuration returned via the scope config.
     *
     * @param array $rows
     * @return void
     */
    private function givenStoredConfig(array $rows): void
    {
        $this->scopeConfig->method('getValue')->with(self::CONFIG_PATH)
            ->willReturn($rows === [] ? null : $this->serializer->serialize($rows));
    }
}
