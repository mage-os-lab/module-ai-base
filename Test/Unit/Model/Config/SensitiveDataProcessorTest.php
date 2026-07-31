<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config;

use Magento\Framework\Encryption\EncryptorInterface;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use MageOS\AiBase\Model\FieldDescriptor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SensitiveDataProcessorTest extends TestCase
{
    /**
     * Service code registered with a field schema in the subject under test.
     */
    private const KNOWN_SERVICE = 'fakeai';

    /**
     * Service code without any registered schema; the name heuristic applies.
     */
    private const UNKNOWN_SERVICE = 'openai';

    private EncryptorInterface&MockObject $encryptor;
    private SensitiveDataProcessor $subject;

    protected function setUp(): void
    {
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->encryptor->method('encrypt')->willReturnCallback(fn (string $v) => '0:3:enc(' . $v . ')');
        $this->encryptor->method('decrypt')->willReturnCallback(
            fn (string $v) => preg_replace('/^0:3:enc\((.*)\)$/', '$1', $v)
        );
        $this->subject = new SensitiveDataProcessor($this->encryptor, [$this->createFakeService()]);
    }

    public function test_encrypt_row_encrypts_only_sensitive_keys(): void
    {
        $result = $this->subject->encryptRow(self::UNKNOWN_SERVICE, [
            'api_key' => 'secret-key',
            'model'   => 'gpt-4o',
        ]);

        self::assertSame('0:3:enc(secret-key)', $result['api_key']);
        self::assertSame('gpt-4o', $result['model']);
    }

    public function test_schema_marked_field_encrypts_despite_non_matching_name(): void
    {
        $result = $this->subject->encryptRow(self::KNOWN_SERVICE, [
            'credential' => 'schema-flagged-secret',
            'model'      => 'fake-1',
        ]);

        self::assertSame('0:3:enc(schema-flagged-secret)', $result['credential']);
        self::assertSame('fake-1', $result['model']);
    }

    public function test_schema_unencrypted_field_stays_plaintext_despite_matching_name(): void
    {
        // 'token' matches the name heuristic, but the schema explicitly marks it unencrypted.
        $result = $this->subject->encryptRow(self::KNOWN_SERVICE, ['token' => 'public-token']);

        self::assertSame('public-token', $result['token']);
    }

    public function test_field_missing_from_known_schema_falls_back_to_name_heuristic(): void
    {
        $result = $this->subject->encryptRow(self::KNOWN_SERVICE, ['secret' => 'orphan-secret']);

        self::assertSame('0:3:enc(orphan-secret)', $result['secret']);
    }

    public function test_unknown_service_code_falls_back_to_name_heuristic(): void
    {
        $result = $this->subject->encryptRow(self::UNKNOWN_SERVICE, [
            'token'      => 'heuristic-secret',
            'credential' => 'not-covered-by-heuristic',
        ]);

        self::assertSame('0:3:enc(heuristic-secret)', $result['token']);
        self::assertSame('not-covered-by-heuristic', $result['credential']);
    }

    public function test_encrypt_row_is_idempotent_for_already_encrypted_values(): void
    {
        $once = $this->subject->encryptRow(self::UNKNOWN_SERVICE, ['api_key' => 'secret-key']);
        $twice = $this->subject->encryptRow(self::UNKNOWN_SERVICE, $once);

        self::assertSame($once, $twice);
    }

    public function test_decrypt_row_round_trips_encrypted_values(): void
    {
        $row = ['api_key' => 'secret-key', 'model' => 'gpt-4o'];
        $encrypted = $this->subject->encryptRow(self::UNKNOWN_SERVICE, $row);
        $decrypted = $this->subject->decryptRow(self::UNKNOWN_SERVICE, $encrypted);

        self::assertSame($row, $decrypted);
    }

    public function test_decrypt_row_round_trips_schema_marked_values(): void
    {
        $row = ['credential' => 'schema-flagged-secret'];
        $encrypted = $this->subject->encryptRow(self::KNOWN_SERVICE, $row);
        $decrypted = $this->subject->decryptRow(self::KNOWN_SERVICE, $encrypted);

        self::assertSame($row, $decrypted);
    }

    public function test_decrypt_row_leaves_plaintext_legacy_values_untouched(): void
    {
        // Uses the legacy "apikey" spelling, which stays supported for third-party providers.
        $result = $this->subject->decryptRow(self::UNKNOWN_SERVICE, ['apikey' => 'legacy-plaintext-key']);

        self::assertSame('legacy-plaintext-key', $result['apikey']);
    }

    public function test_empty_and_non_string_values_are_ignored(): void
    {
        $row = ['apikey' => '', 'token' => null, 'secret' => ['nested' => 'x']];

        self::assertSame($row, $this->subject->encryptRow(self::UNKNOWN_SERVICE, $row));
    }

    public function test_mask_row_obscures_only_sensitive_keys(): void
    {
        $result = $this->subject->maskRow(self::UNKNOWN_SERVICE, [
            'api_key' => '0:3:enc(secret-key)',
            'model'   => 'gpt-4o',
        ]);

        self::assertSame(SensitiveDataProcessor::OBSCURED_PLACEHOLDER, $result['api_key']);
        self::assertSame('gpt-4o', $result['model']);
    }

    public function test_mask_row_obscures_schema_marked_keys(): void
    {
        $result = $this->subject->maskRow(self::KNOWN_SERVICE, [
            'credential' => '0:3:enc(secret)',
            'model'      => 'fake-1',
        ]);

        self::assertSame(SensitiveDataProcessor::OBSCURED_PLACEHOLDER, $result['credential']);
        self::assertSame('fake-1', $result['model']);
    }

    public function test_mask_row_leaves_empty_sensitive_values_untouched(): void
    {
        $row = ['api_key' => '', 'model' => 'gpt-4o'];

        self::assertSame($row, $this->subject->maskRow(self::UNKNOWN_SERVICE, $row));
    }

    public function test_restore_row_replaces_placeholder_with_stored_value(): void
    {
        $result = $this->subject->restoreRow(
            self::UNKNOWN_SERVICE,
            ['api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER, 'model' => 'gpt-4.1'],
            ['api_key' => '0:3:enc(secret-key)', 'model' => 'gpt-4o'],
        );

        self::assertSame(['api_key' => '0:3:enc(secret-key)', 'model' => 'gpt-4.1'], $result);
    }

    public function test_restore_row_replaces_placeholder_for_schema_marked_field(): void
    {
        $result = $this->subject->restoreRow(
            self::KNOWN_SERVICE,
            ['credential' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER],
            ['credential' => '0:3:enc(secret)'],
        );

        self::assertSame(['credential' => '0:3:enc(secret)'], $result);
    }

    public function test_restore_row_keeps_newly_entered_values(): void
    {
        $result = $this->subject->restoreRow(
            self::UNKNOWN_SERVICE,
            ['api_key' => 'brand-new-key'],
            ['api_key' => '0:3:enc(old-key)'],
        );

        self::assertSame(['api_key' => 'brand-new-key'], $result);
    }

    public function test_restore_row_discards_placeholder_without_stored_value(): void
    {
        $result = $this->subject->restoreRow(
            self::UNKNOWN_SERVICE,
            ['api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER],
            [],
        );

        self::assertSame(['api_key' => ''], $result);
    }

    public function test_restore_row_ignores_placeholder_in_non_sensitive_fields(): void
    {
        $result = $this->subject->restoreRow(
            self::UNKNOWN_SERVICE,
            ['model' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER],
            ['model' => 'gpt-4o'],
        );

        self::assertSame(['model' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER], $result);
    }

    public function test_mask_restore_encrypt_round_trip_keeps_stored_credential(): void
    {
        $code = self::UNKNOWN_SERVICE;
        $stored = $this->subject->encryptRow($code, ['api_key' => 'secret-key', 'model' => 'gpt-4o']);

        $submitted = $this->subject->maskRow($code, $stored);
        $saved = $this->subject->encryptRow($code, $this->subject->restoreRow($code, $submitted, $stored));

        self::assertSame($stored, $saved);
    }

    public function test_invalid_service_registration_is_rejected(): void
    {
        $subject = new SensitiveDataProcessor($this->encryptor, ['not-a-service']);

        $this->expectException(\InvalidArgumentException::class);
        $subject->encryptRow(self::KNOWN_SERVICE, ['api_key' => 'x']);
    }

    /**
     * Build a fake provider with a field schema exercising all sensitivity paths:
     * an encrypted field whose name the heuristic would miss ("credential"), an
     * explicitly unencrypted field whose name the heuristic would match ("token"),
     * and a plain unencrypted field ("model").
     *
     * @return AiServiceConfigurationInterface
     */
    private function createFakeService(): AiServiceConfigurationInterface
    {
        return new class implements AiServiceConfigurationInterface {
            /**
             * @inheritdoc
             */
            public function getCode(): string
            {
                return 'fakeai';
            }

            /**
             * @inheritdoc
             */
            public function getName(): string
            {
                return 'Fake AI';
            }

            /**
             * @inheritdoc
             */
            public function getConfigurationFields(): array
            {
                return [
                    new FieldDescriptor(
                        name: 'credential',
                        label: 'Credential',
                        type: FieldDescriptorInterface::TYPE_TEXT,
                        encrypted: true,
                    ),
                    new FieldDescriptor(
                        name: 'token',
                        label: 'Public Token',
                        type: FieldDescriptorInterface::TYPE_TEXT,
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
}
