<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

/**
 * Maps service codes to their Symfony AI bridge and the composer package that provides it.
 *
 * The bridges are a soft dependency: the module stores and serves provider configuration with
 * none of them installed, and other modules may talk to a provider with their own HTTP client.
 * They are only needed by the bundled client layer (`AiClientInterface`) and the admin's Test
 * Connection button.
 *
 * Since symfony/ai-platform 0.12 the bridges ship as one package per provider rather than inside
 * the platform package, so knowing the package name per service code is what lets the admin form
 * tell an administrator exactly what to install.
 */
class BridgeRegistry
{
    /**
     * Key of the bridge factory FQCN within a bridge definition.
     */
    private const KEY_FACTORY = 'factory';

    /**
     * Key of the composer package name within a bridge definition.
     */
    private const KEY_PACKAGE = 'package';

    /**
     * @param array $bridges Service code => ['factory' => bridge factory FQCN, 'package' => composer package]
     */
    public function __construct(
        private readonly array $bridges = [],
    ) {
    }

    /**
     * Whether a bridge exists upstream for this service code at all.
     *
     * False means no bridge has been released for the provider, so no package can make the
     * bundled client work with it; contrast isAvailable(), which is about installation.
     *
     * @param string $serviceCode
     * @return bool
     */
    public function isSupported(string $serviceCode): bool
    {
        return isset($this->bridges[$serviceCode]);
    }

    /**
     * Whether this service's bridge is installed and usable.
     *
     * @param string $serviceCode
     * @return bool
     */
    public function isAvailable(string $serviceCode): bool
    {
        $factoryClass = $this->getFactoryClass($serviceCode);

        return $factoryClass !== null
            && class_exists($factoryClass)
            && method_exists($factoryClass, 'createPlatform');
    }

    /**
     * Bridge factory FQCN for a service code, or null when no bridge is registered.
     *
     * @param string $serviceCode
     * @return string|null
     */
    public function getFactoryClass(string $serviceCode): ?string
    {
        $factoryClass = $this->bridges[$serviceCode][self::KEY_FACTORY] ?? null;

        return is_string($factoryClass) && $factoryClass !== '' ? $factoryClass : null;
    }

    /**
     * Composer package providing this service's bridge, or null when none is registered.
     *
     * @param string $serviceCode
     * @return string|null
     */
    public function getPackage(string $serviceCode): ?string
    {
        $package = $this->bridges[$serviceCode][self::KEY_PACKAGE] ?? null;

        return is_string($package) && $package !== '' ? $package : null;
    }
}
