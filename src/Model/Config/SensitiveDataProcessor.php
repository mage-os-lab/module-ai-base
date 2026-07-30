<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Encrypts/decrypts sensitive keys inside a single service configuration array.
 *
 * Shared by the config backend model (write path) and the service selector (read path).
 */
class SensitiveDataProcessor
{
    private const SENSITIVE_KEYS = ['apikey', 'api_key', 'token', 'secret'];

    /**
     * Magento encryptor envelope, e.g. "0:3:<base64>". Values not matching this
     * pattern are treated as plaintext so pre-encryption rows keep working.
     */
    private const ENCRYPTED_ENVELOPE_PATTERN = '/^\d+:\d+:.+$/';

    /**
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    /**
     * Encrypt sensitive values in a service configuration row.
     *
     * @param array $configuration
     * @return array
     */
    public function encryptRow(array $configuration): array
    {
        return $this->processRow(
            $configuration,
            fn (string $value) => $this->isEncrypted($value) ? $value : $this->encryptor->encrypt($value),
        );
    }

    /**
     * Decrypt sensitive values in a service configuration row.
     *
     * Plaintext values (rows saved before encryption was introduced) are returned unchanged.
     *
     * @param array $configuration
     * @return array
     */
    public function decryptRow(array $configuration): array
    {
        return $this->processRow(
            $configuration,
            fn (string $value) => $this->isEncrypted($value) ? $this->encryptor->decrypt($value) : $value,
        );
    }

    /**
     * Apply a processor to every sensitive string value in the row.
     *
     * @param array $configuration
     * @param callable $processor
     * @return array
     */
    private function processRow(array $configuration, callable $processor): array
    {
        foreach ($configuration as $key => $value) {
            if (is_string($value) && $value !== '' && $this->isSensitive((string)$key)) {
                $configuration[$key] = $processor($value);
            }
        }

        return $configuration;
    }

    /**
     * Whether a configuration key holds a credential.
     *
     * @param string $key
     * @return bool
     */
    private function isSensitive(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }

    /**
     * Whether a value already carries the encryptor envelope.
     *
     * @param string $value
     * @return bool
     */
    private function isEncrypted(string $value): bool
    {
        return (bool)preg_match(self::ENCRYPTED_ENVELOPE_PATTERN, $value);
    }
}
