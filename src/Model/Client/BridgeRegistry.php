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
     * Key of the request-option dialect within a bridge definition.
     */
    private const KEY_DIALECT = 'dialect';

    /**
     * Key of the model catalogue FQCN within a bridge definition.
     */
    private const KEY_CATALOG = 'catalog';

    /**
     * @param array<string,array{factory?:string,package?:string,dialect?:string,catalog?:string}> $bridges
     *        Service code => bridge, package, request-option dialect and model catalogue
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
        return $this->readString($serviceCode, self::KEY_FACTORY);
    }

    /**
     * Composer package providing this service's bridge, or null when none is registered.
     *
     * @param string $serviceCode
     * @return string|null
     */
    public function getPackage(string $serviceCode): ?string
    {
        return $this->readString($serviceCode, self::KEY_PACKAGE);
    }

    /**
     * Request-option dialect this service's bridge speaks, or null when it declares none.
     *
     * Providers reuse a handful of request shapes (the OpenAI chat-completions body, the Responses
     * API body, Anthropic's messages body, Gemini's generationConfig, Ollama's nested options), and
     * which one a service speaks is a property of its bridge. Naming it here rather than in a
     * second list keeps registering a provider to one entry. See Model\Client\OptionNormalizer.
     *
     * @param string $serviceCode
     * @return string|null
     */
    public function getDialect(string $serviceCode): ?string
    {
        return $this->readString($serviceCode, self::KEY_DIALECT);
    }

    /**
     * Model catalogue FQCN for a service code, or null when the bridge declares none.
     *
     * A bridge only routes models its catalogue lists, and that list is baked into the released
     * package: a model the provider shipped after it cannot be reached at all, however valid the
     * credentials. Knowing the catalogue class is what lets an administrator's own choice be
     * registered alongside the built-in list. See Model\Client\ClientFactory.
     *
     * @param string $serviceCode
     * @return string|null
     */
    public function getCatalogClass(string $serviceCode): ?string
    {
        return $this->readString($serviceCode, self::KEY_CATALOG);
    }

    /**
     * Read a non-empty string off a bridge definition.
     *
     * @param string $serviceCode
     * @param string $key
     * @return string|null
     */
    private function readString(string $serviceCode, string $key): ?string
    {
        $value = $this->bridges[$serviceCode][$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
