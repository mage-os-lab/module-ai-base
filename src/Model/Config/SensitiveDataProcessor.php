<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use Magento\Framework\Encryption\EncryptorInterface;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

/**
 * Encrypts/decrypts sensitive keys inside a single service configuration array.
 *
 * Sensitivity is decided by the registered provider field schema: a field is sensitive
 * when its descriptor reports isEncrypted(). For service codes without a registered
 * schema, or for fields the schema does not describe, a field-name heuristic is used
 * as a fallback (see SENSITIVE_KEYS).
 *
 * Shared by the config backend model (write path) and the service selector (read path).
 */
class SensitiveDataProcessor
{
    /**
     * Placeholder shown in the admin form instead of a stored credential.
     */
    public const OBSCURED_PLACEHOLDER = '******';

    /**
     * Name-based fallback for fields not covered by a registered field schema.
     *
     * Kept for two reasons: stored rows may belong to a third-party provider whose
     * module was since removed (its schema is no longer registered, but its stored
     * credentials must stay protected), and as defense in depth for provider fields
     * that hold credentials but were not flagged as encrypted.
     */
    private const SENSITIVE_KEYS = ['apikey', 'api_key', 'token', 'secret'];

    /**
     * Magento encryptor envelope, e.g. "0:3:<base64>". Values not matching this
     * pattern are treated as plaintext so pre-encryption rows keep working.
     */
    private const ENCRYPTED_ENVELOPE_PATTERN = '/^\d+:\d+:.+$/';

    /**
     * Lazily built map of service code => [field name => encrypted flag].
     *
     * @var array<string, array<string, bool>>|null
     */
    private ?array $fieldSchema = null;

    /**
     * @param EncryptorInterface $encryptor
     * @param AiServiceConfigurationInterface[] $services Registered AI backends providing the field schema
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly array $services = [],
    ) {
    }

    /**
     * Encrypt sensitive values in a service configuration row.
     *
     * @param string $serviceCode
     * @param array $configuration
     * @return array
     */
    public function encryptRow(string $serviceCode, array $configuration): array
    {
        return $this->processRow(
            $serviceCode,
            $configuration,
            fn (string $value) => $this->isEncrypted($value) ? $value : $this->encryptor->encrypt($value),
        );
    }

    /**
     * Decrypt sensitive values in a service configuration row.
     *
     * Plaintext values (rows saved before encryption was introduced) are returned unchanged.
     *
     * @param string $serviceCode
     * @param array $configuration
     * @return array
     */
    public function decryptRow(string $serviceCode, array $configuration): array
    {
        return $this->processRow(
            $serviceCode,
            $configuration,
            fn (string $value) => $this->isEncrypted($value) ? $this->encryptor->decrypt($value) : $value,
        );
    }

    /**
     * Replace sensitive values with the obscured placeholder for admin form display.
     *
     * @param string $serviceCode
     * @param array $configuration
     * @return array
     */
    public function maskRow(string $serviceCode, array $configuration): array
    {
        return $this->processRow(
            $serviceCode,
            $configuration,
            static fn (): string => self::OBSCURED_PLACEHOLDER,
        );
    }

    /**
     * Restore previously stored credentials where the submitted value is the placeholder.
     *
     * A submitted placeholder means "keep the stored value"; if no stored value exists
     * for the key (e.g. the row is new), the placeholder is discarded to avoid persisting
     * the literal placeholder as a credential.
     *
     * @param string $serviceCode
     * @param array $configuration Submitted service configuration row
     * @param array $previous Previously stored (still encrypted) configuration row
     * @return array
     */
    public function restoreRow(string $serviceCode, array $configuration, array $previous): array
    {
        foreach ($configuration as $key => $value) {
            if ($value === self::OBSCURED_PLACEHOLDER && $this->isSensitive($serviceCode, (string)$key)) {
                $stored = $previous[$key] ?? '';
                $configuration[$key] = is_string($stored) ? $stored : '';
            }
        }

        return $configuration;
    }

    /**
     * Apply a processor to every sensitive string value in the row.
     *
     * @param string $serviceCode
     * @param array $configuration
     * @param callable $processor
     * @return array
     */
    private function processRow(string $serviceCode, array $configuration, callable $processor): array
    {
        foreach ($configuration as $key => $value) {
            if (is_string($value) && $value !== '' && $this->isSensitive($serviceCode, (string)$key)) {
                $configuration[$key] = $processor($value);
            }
        }

        return $configuration;
    }

    /**
     * Whether a configuration key holds a credential.
     *
     * The registered field schema is authoritative when it describes the field;
     * otherwise the SENSITIVE_KEYS name heuristic applies (see its docblock).
     *
     * @param string $serviceCode
     * @param string $key
     * @return bool
     */
    private function isSensitive(string $serviceCode, string $key): bool
    {
        $schema = $this->getFieldSchema();
        if (isset($schema[$serviceCode][$key])) {
            return $schema[$serviceCode][$key];
        }

        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }

    /**
     * Build (once) the encrypted-field schema from the registered services.
     *
     * @return array<string, array<string, bool>>
     * @throws \InvalidArgumentException When a registered service has the wrong type
     */
    private function getFieldSchema(): array
    {
        if ($this->fieldSchema === null) {
            $this->fieldSchema = [];
            foreach ($this->services as $service) {
                if (!$service instanceof AiServiceConfigurationInterface) {
                    throw new \InvalidArgumentException(sprintf(
                        'Each registered service must implement %s, got %s',
                        AiServiceConfigurationInterface::class,
                        get_debug_type($service),
                    ));
                }
                foreach ($service->getConfigurationFields() as $field) {
                    $this->fieldSchema[$service->getCode()][$field->getName()] = $field->isEncrypted();
                }
            }
        }

        return $this->fieldSchema;
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
