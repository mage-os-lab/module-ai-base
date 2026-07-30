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
            'apikey' => 'secret-key',
            'model'  => 'gpt-4o',
        ]);

        self::assertSame('0:3:enc(secret-key)', $result['apikey']);
        self::assertSame('gpt-4o', $result['model']);
    }

    public function test_encrypt_row_is_idempotent_for_already_encrypted_values(): void
    {
        $once = $this->subject->encryptRow(['apikey' => 'secret-key']);
        $twice = $this->subject->encryptRow($once);

        self::assertSame($once, $twice);
    }

    public function test_decrypt_row_round_trips_encrypted_values(): void
    {
        $encrypted = $this->subject->encryptRow(['apikey' => 'secret-key', 'model' => 'gpt-4o']);
        $decrypted = $this->subject->decryptRow($encrypted);

        self::assertSame(['apikey' => 'secret-key', 'model' => 'gpt-4o'], $decrypted);
    }

    public function test_decrypt_row_leaves_plaintext_legacy_values_untouched(): void
    {
        $result = $this->subject->decryptRow(['apikey' => 'legacy-plaintext-key']);

        self::assertSame('legacy-plaintext-key', $result['apikey']);
    }

    public function test_empty_and_non_string_values_are_ignored(): void
    {
        $row = ['apikey' => '', 'token' => null, 'secret' => ['nested' => 'x']];

        self::assertSame($row, $this->subject->encryptRow($row));
    }
}
