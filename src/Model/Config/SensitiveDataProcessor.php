<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Model\ServiceRegistry;

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
     * Fields naming the host a row's credentials are sent to.
     *
     * Moving one of these is what turns a stored credential into something the person editing the
     * row can read back, which is why restoreRow() refuses to carry a masked value across a change
     * to any of them.
     */
    private const ENDPOINT_KEYS = ['base_url', 'endpoint'];

    /**
     * Magento encryptor envelope, e.g. "0:3:<base64>". Values not matching this
     * pattern are treated as plaintext so pre-encryption rows keep working.
     */
    private const ENCRYPTED_ENVELOPE_PATTERN = '/^\d+:\d+:.+$/';

    /**
     * Lazily built map of service code => [field name => encrypted flag].
     *
     * @var array<string,array<string,bool>>|null
     */
    private ?array $fieldSchema = null;

    /**
     * @param EncryptorInterface $encryptor
     * @param ServiceRegistry $serviceRegistry Registered AI backends providing the field schema
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly ServiceRegistry $serviceRegistry,
    ) {
    }

    /**
     * Encrypt sensitive values in a service configuration row.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration
     * @return array<array-key,mixed>
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
     * @param array<array-key,mixed> $configuration
     * @return array<array-key,mixed>
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
     * @param array<array-key,mixed> $configuration
     * @return array<array-key,mixed>
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
     * @param array<array-key,mixed> $configuration Submitted service configuration row
     * @param array<array-key,mixed> $previous Previously stored (still encrypted) configuration row
     * @return array<array-key,mixed>
     */
    public function restoreRow(string $serviceCode, array $configuration, array $previous): array
    {
        $redirected = $this->isRedirected($configuration, $previous);

        foreach ($configuration as $key => $value) {
            if ($value === self::OBSCURED_PLACEHOLDER && $this->isSensitive($serviceCode, (string)$key)) {
                if ($redirected) {
                    throw new LocalizedException(
                        __(
                            'The endpoint of the "%1" service changed, so its %2 has to be entered '
                            . 'again. A stored credential is never carried over to a host it was '
                            . 'not issued for.',
                            $serviceCode,
                            str_replace('_', ' ', (string)$key)
                        )
                    );
                }
                $stored = $previous[$key] ?? '';
                $configuration[$key] = is_string($stored) ? $stored : '';
            }
        }

        return $configuration;
    }

    /**
     * Whether this save points an existing row at a different host.
     *
     * The obscured placeholder exists so an administrator can save the form without ever seeing a
     * stored credential. An editable endpoint would hand it back to them: point the row at a host
     * you control, leave the key masked so it is restored from storage, press Test Connection, and
     * read the credential off your own server. Whoever moves the endpoint therefore has to supply
     * the credential for it, which is something only someone who already holds it can do.
     *
     * A brand-new row has nothing stored to leak, so this only guards edits.
     *
     * @param array<array-key,mixed> $configuration Submitted service configuration row
     * @param array<array-key,mixed> $previous Previously stored configuration row
     * @return bool
     */
    private function isRedirected(array $configuration, array $previous): bool
    {
        foreach (self::ENDPOINT_KEYS as $key) {
            if (!array_key_exists($key, $previous)) {
                continue;
            }
            $before = is_string($previous[$key]) ? trim($previous[$key]) : '';
            $after = is_string($configuration[$key] ?? null) ? trim((string)$configuration[$key]) : '';
            if (rtrim($before, '/') !== rtrim($after, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply a processor to every sensitive string value in the row.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration
     * @param callable $processor
     * @return array<array-key,mixed>
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
     * @return array<string,array<string,bool>>
     */
    private function getFieldSchema(): array
    {
        if ($this->fieldSchema === null) {
            $this->fieldSchema = [];
            foreach ($this->serviceRegistry->getAll() as $code => $service) {
                foreach ($service->getConfigurationFields() as $field) {
                    $this->fieldSchema[$code][$field->getName()] = $field->isEncrypted();
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
