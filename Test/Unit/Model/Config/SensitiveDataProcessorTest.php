<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config;

use Magento\Framework\Encryption\EncryptorInterface;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SensitiveDataProcessorTest extends TestCase
{
    private EncryptorInterface&MockObject $encryptor;
    private SensitiveDataProcessor $subject;

    protected function setUp(): void
    {
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->encryptor->method('encrypt')->willReturnCallback(fn (string $v) => '0:3:enc(' . $v . ')');
        $this->encryptor->method('decrypt')->willReturnCallback(
            fn (string $v) => preg_replace('/^0:3:enc\((.*)\)$/', '$1', $v)
        );
        $this->subject = new SensitiveDataProcessor($this->encryptor);
    }

    public function test_encrypt_row_encrypts_only_sensitive_keys(): void
    {
        $result = $this->subject->encryptRow([
            'api_key' => 'secret-key',
            'model'   => 'gpt-4o',
        ]);

        self::assertSame('0:3:enc(secret-key)', $result['api_key']);
        self::assertSame('gpt-4o', $result['model']);
    }

    public function test_encrypt_row_is_idempotent_for_already_encrypted_values(): void
    {
        $once = $this->subject->encryptRow(['api_key' => 'secret-key']);
        $twice = $this->subject->encryptRow($once);

        self::assertSame($once, $twice);
    }

    public function test_decrypt_row_round_trips_encrypted_values(): void
    {
        $encrypted = $this->subject->encryptRow(['api_key' => 'secret-key', 'model' => 'gpt-4o']);
        $decrypted = $this->subject->decryptRow($encrypted);

        self::assertSame(['api_key' => 'secret-key', 'model' => 'gpt-4o'], $decrypted);
    }

    public function test_decrypt_row_leaves_plaintext_legacy_values_untouched(): void
    {
        // Uses the legacy "apikey" spelling, which stays supported for third-party providers.
        $result = $this->subject->decryptRow(['apikey' => 'legacy-plaintext-key']);

        self::assertSame('legacy-plaintext-key', $result['apikey']);
    }

    public function test_empty_and_non_string_values_are_ignored(): void
    {
        $row = ['apikey' => '', 'token' => null, 'secret' => ['nested' => 'x']];

        self::assertSame($row, $this->subject->encryptRow($row));
    }

    public function test_mask_row_obscures_only_sensitive_keys(): void
    {
        $result = $this->subject->maskRow([
            'api_key' => '0:3:enc(secret-key)',
            'model'   => 'gpt-4o',
        ]);

        self::assertSame(SensitiveDataProcessor::OBSCURED_PLACEHOLDER, $result['api_key']);
        self::assertSame('gpt-4o', $result['model']);
    }

    public function test_mask_row_leaves_empty_sensitive_values_untouched(): void
    {
        $row = ['api_key' => '', 'model' => 'gpt-4o'];

        self::assertSame($row, $this->subject->maskRow($row));
    }

    public function test_restore_row_replaces_placeholder_with_stored_value(): void
    {
        $result = $this->subject->restoreRow(
            ['api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER, 'model' => 'gpt-4.1'],
            ['api_key' => '0:3:enc(secret-key)', 'model' => 'gpt-4o'],
        );

        self::assertSame(['api_key' => '0:3:enc(secret-key)', 'model' => 'gpt-4.1'], $result);
    }

    public function test_restore_row_keeps_newly_entered_values(): void
    {
        $result = $this->subject->restoreRow(
            ['api_key' => 'brand-new-key'],
            ['api_key' => '0:3:enc(old-key)'],
        );

        self::assertSame(['api_key' => 'brand-new-key'], $result);
    }

    public function test_restore_row_discards_placeholder_without_stored_value(): void
    {
        $result = $this->subject->restoreRow(
            ['api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER],
            [],
        );

        self::assertSame(['api_key' => ''], $result);
    }

    public function test_restore_row_ignores_placeholder_in_non_sensitive_fields(): void
    {
        $result = $this->subject->restoreRow(
            ['model' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER],
            ['model' => 'gpt-4o'],
        );

        self::assertSame(['model' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER], $result);
    }

    public function test_mask_restore_encrypt_round_trip_keeps_stored_credential(): void
    {
        $stored = $this->subject->encryptRow(['api_key' => 'secret-key', 'model' => 'gpt-4o']);

        $submitted = $this->subject->maskRow($stored);
        $saved = $this->subject->encryptRow($this->subject->restoreRow($submitted, $stored));

        self::assertSame($stored, $saved);
    }
}
