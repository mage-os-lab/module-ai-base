<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\AiServices\LmStudio;
use MageOS\AiBase\AiServices\Ollama;
use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;

/**
 * Builds AiClientInterface instances backed by symfony/ai-platform provider bridges.
 *
 * The bridge factory class per service code is supplied via di.xml, so
 * third-party modules can register additional providers (or replace the
 * Symfony AI implementation entirely by preferencing AiClientFactoryInterface).
 */
class ClientFactory implements AiClientFactoryInterface
{
    /**
     * @param AiServiceSelectorInterface $serviceSelector
     * @param SymfonyAiClientFactory $clientFactory
     * @param BridgeRegistry $bridgeRegistry Service code => bridge factory and composer package
     */
    public function __construct(
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly SymfonyAiClientFactory $clientFactory,
        private readonly BridgeRegistry $bridgeRegistry,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function create(?string $serviceCode = null): AiClientInterface
    {
        $service = $serviceCode === null
            ? $this->resolveDefaultService()
            : $this->resolveRequestedService($serviceCode);

        return $this->buildClient($service);
    }

    /**
     * @inheritdoc
     */
    public function createById(string $serviceId): AiClientInterface
    {
        $service = $this->serviceSelector->getById($serviceId);
        if (!$service instanceof AiServiceInterface) {
            throw new LocalizedException(
                __(
                    'The selected AI service (id "%1") is not available. It may have been deleted '
                    . 'or disabled. Pick another one, or restore it under '
                    . 'Stores > Configuration > Mage-OS > AI Configuration.',
                    $serviceId
                )
            );
        }

        return $this->buildClient($service);
    }

    /**
     * Build the client for an already resolved service row.
     *
     * @param AiServiceInterface $service
     * @return AiClientInterface
     * @throws LocalizedException
     */
    private function buildClient(AiServiceInterface $service): AiClientInterface
    {
        return $this->clientFactory->create([
            'platform' => $this->createPlatform($service),
            'model' => $this->resolveModel($service),
            'serviceCode' => $service->getCode(),
            'serviceId' => $service->getId(),
        ]);
    }

    /**
     * The model the configured row selected, refused rather than passed on when it is missing.
     *
     * Every platform takes the model as a required name, so an unset one reaches the provider as an
     * empty string and comes back as whatever that provider says about a model it cannot find —
     * a 404 on an empty path, most usefully. Naming the row here is the difference between an
     * administrator knowing which service to go fix and reading a provider's error about nothing.
     *
     * @param AiServiceInterface $service
     * @return non-empty-string
     * @throws LocalizedException
     */
    private function resolveModel(AiServiceInterface $service): string
    {
        $model = $service->getConfiguration()['model'] ?? '';
        if (!is_string($model) || $model === '') {
            throw new LocalizedException(
                __(
                    'AI service "%1" has no model selected. '
                    . 'Pick one under Stores > Configuration > Mage-OS > AI Configuration.',
                    $service->getCode()
                )
            );
        }

        return $model;
    }

    /**
     * Resolve the service for an explicit code.
     *
     * An unusable service is returned rather than skipped: the caller named this provider, and
     * quietly billing a different one would be worse than failing. createPlatform() reports why.
     *
     * @param string $serviceCode
     * @return AiServiceInterface
     * @throws LocalizedException
     */
    private function resolveRequestedService(string $serviceCode): AiServiceInterface
    {
        $service = $this->serviceSelector->getByCode($serviceCode)[0] ?? null;
        if (!$service instanceof AiServiceInterface) {
            throw new LocalizedException(
                __(
                    'No AI service configured for code "%1". '
                    . 'Configure one under Stores > Configuration > Mage-OS > AI Configuration.',
                    $serviceCode
                )
            );
        }

        return $service;
    }

    /**
     * Resolve the service to use when the caller did not name one.
     *
     * The first configured service whose bridge is installed wins, rather than simply the first
     * configured service. Ordering is the admin's row order, which has nothing to do with which
     * bridges an install happens to have: without this, one unusable provider sitting at the top
     * of the list disables the no-argument entry point for every consumer, even when a perfectly
     * usable provider is configured below it.
     *
     * @return AiServiceInterface
     * @throws LocalizedException
     */
    private function resolveDefaultService(): AiServiceInterface
    {
        $services = $this->serviceSelector->getAll();
        if ($services === []) {
            throw new LocalizedException(
                __(
                    'No AI service configured. '
                    . 'Configure one under Stores > Configuration > Mage-OS > AI Configuration.'
                )
            );
        }

        foreach ($services as $service) {
            if ($this->bridgeRegistry->isAvailable($service->getCode())) {
                return $service;
            }
        }

        throw new LocalizedException(
            __(
                'None of the configured AI services can be used through the bundled client: %1. '
                . 'Install the bridge package for one of them, or request a specific service by code.',
                $this->describeUnusable($services)
            )
        );
    }

    /**
     * Human-readable list of configured services and what each one needs, for the error above.
     *
     * @param AiServiceInterface[] $services
     * @return string
     */
    private function describeUnusable(array $services): string
    {
        $described = [];
        foreach ($services as $service) {
            $code = $service->getCode();
            $package = $this->bridgeRegistry->getPackage($code);
            $described[$code] = $package !== null
                ? sprintf('%s (needs %s)', $code, $package)
                : sprintf('%s (no bridge released)', $code);
        }

        return implode(', ', $described);
    }

    /**
     * Instantiate the Symfony AI platform for a configured service.
     *
     * @param AiServiceInterface $service
     * @return object \Symfony\AI\Platform\PlatformInterface
     * @throws LocalizedException
     */
    private function createPlatform(AiServiceInterface $service): object
    {
        $code = $service->getCode();
        if (!$this->bridgeRegistry->isSupported($code)) {
            throw new LocalizedException(
                __(
                    'No Symfony AI bridge has been released for service "%1" yet, so it cannot be '
                    . 'used through the bundled client. Its configuration can still be read by '
                    . 'modules that call the provider themselves.',
                    $code
                )
            );
        }
        if (!$this->bridgeRegistry->isAvailable($code)) {
            throw new LocalizedException(
                __(
                    'The Symfony AI bridge for "%1" is not installed. Run "composer require %2".',
                    $code,
                    (string) $this->bridgeRegistry->getPackage($code)
                )
            );
        }
        $factoryClass = (string) $this->bridgeRegistry->getFactoryClass($code);

        $config = $service->getConfiguration();

        // Bridge Factory::createPlatform() signatures vary by provider (verified
        // against symfony/ai-platform v0.11.0): hosted providers take an API key;
        // local runtimes take an endpoint/base URL; Azure takes endpoint +
        // deployment (the selected model) + API version + key.
        //
        // Every arm ends in optionalArguments() so that no provider is left out of the model
        // catalogue: the two that take their endpoint positionally are also the two with a
        // free-text model field, which makes them the likeliest to hold a model no static
        // catalogue lists.
        $platform = match ($code) {
            'ollama' => $factoryClass::createPlatform(
                $this->resolveBaseUrl($config, Ollama::DEFAULT_BASE_URL),
                ...$this->optionalArguments($factoryClass, $code, $config),
            ),
            'lmstudio' => $factoryClass::createPlatform(
                $this->resolveBaseUrl($config, LmStudio::DEFAULT_BASE_URL),
                ...$this->optionalArguments($factoryClass, $code, $config),
            ),
            'azure' => $factoryClass::createPlatform(
                $this->stringValue($config, 'endpoint'),
                $this->stringValue($config, 'deployment') ?: $this->stringValue($config, 'model'),
                $this->stringValue($config, 'api_version', '2024-10-21'),
                $this->stringValue($config, 'api_key'),
                ...$this->optionalArguments($factoryClass, $code, $config),
            ),
            default => $factoryClass::createPlatform(
                $this->stringValue($config, 'api_key'),
                ...$this->optionalArguments($factoryClass, $code, $config),
            ),
        };

        // The factory class comes from di.xml, so what it hands back is only ever as good as the
        // registration. Checking here names the bridge that misbehaved; without it the mistake
        // surfaces later as a call to a method on null, from a class that never mentions the
        // registration that caused it. Deliberately not an instanceof PlatformInterface: this
        // runs in installs where symfony/ai-platform is absent, where that would reject
        // everything, and a bridge that got this far has already been found on the autoloader.
        if (!is_object($platform)) {
            throw new LocalizedException(
                __(
                    'The registered bridge factory for service "%1" did not return a platform object.',
                    $code
                )
            );
        }

        return $platform;
    }

    /**
     * Named arguments for a bridge factory beyond the API key, limited to the ones it declares.
     *
     * Bridge signatures differ, and they gain parameters between releases. Asking the factory what
     * it accepts means an install running an older bridge silently goes without rather than dying
     * on an unknown named argument, and a bridge that later grows one starts receiving it with no
     * change here.
     *
     * @param string $factoryClass Bridge factory FQCN
     * @param string $code Service code
     * @param array<string,mixed> $config Stored service configuration
     * @return array<string,mixed> Argument name => value
     */
    private function optionalArguments(string $factoryClass, string $code, array $config): array
    {
        $accepted = [];
        foreach ((new \ReflectionMethod($factoryClass, 'createPlatform'))->getParameters() as $parameter) {
            $accepted[$parameter->getName()] = true;
        }

        $model = $config['model'] ?? null;

        $arguments = [];
        $catalog = $this->createCatalog($code, is_string($model) ? $model : '');
        if ($catalog !== null && isset($accepted['modelCatalog'])) {
            $arguments['modelCatalog'] = $catalog;
        }
        return $arguments;
    }

    /**
     * Read a string off a stored service row, treating anything that is not one as absent.
     *
     * The row is whatever json_decode made of the stored value, so a hand-edited or half-saved
     * config can hold an array or an int where a credential belongs. Casting one would hand the
     * bridge "Array" and get back an authentication failure; letting it through raises a TypeError
     * from inside the bridge, naming neither the row nor the field.
     *
     * @param array<string,mixed> $config
     * @param string $key
     * @param string $default
     * @return string
     */
    private function stringValue(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Build the bridge's model catalogue, with the configured model added when it is not in it.
     *
     * A bridge routes by catalogue membership alone, and the catalogue is a static list frozen at
     * the version installed. Models outlive releases: the provider ships one, the administrator
     * picks it up through Refresh Models, and the call then fails at the router with "no provider
     * found" long before any request goes out. Registering the administrator's own choice keeps
     * that decision with the person who made it, and a model the provider does not actually serve
     * fails against the provider, saying so, instead of being blocked locally on stale data.
     *
     * Capabilities are copied from the most capable model already in the catalogue, because they
     * only gate features a caller asks for, and the provider is the real authority on what its own
     * model can do.
     *
     * @param string $code Service code
     * @param string $model Configured model
     * @return object|null \Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface, or null when the
     *         bridge registers no catalogue or the configured model is already in it
     */
    private function createCatalog(string $code, string $model): ?object
    {
        $catalogClass = $this->bridgeRegistry->getCatalogClass($code);
        if ($model === '' || $catalogClass === null || !class_exists($catalogClass)) {
            return null;
        }

        // A catalogue whose constructor takes nothing cannot be extended, and one that demands an
        // argument is not the shape this expects. Both exist upstream (FallbackModelCatalog takes
        // none), and PHP would swallow the first silently: the extra argument is dropped, the model
        // never lands, and the router rejects it exactly as if this code were not here.
        try {
            $constructor = (new \ReflectionClass($catalogClass))->getConstructor();
            if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
                return null;
            }

            $models = $this->readModels(new $catalogClass());
            if ($models === [] || isset($models[$model])) {
                return null;
            }

            $template = $this->mostCapableEntry($models);
            if ($template === []) {
                return null;
            }

            $catalog = new $catalogClass([$model => $template]);

            // Proof rather than assumption: a catalogue that merged the entry answers for it. One
            // that ignored the argument is no better than none, and passing it would only pin the
            // platform to a shape this code guessed at.
            return isset($this->readModels($catalog)[$model]) ? $catalog : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Read the model map off a bridge catalogue, or an empty one when it does not offer it.
     *
     * The class comes from di.xml and this module never requires the bridge packages, so what it
     * names is only ever as good as the registration.
     *
     * @param object $catalog
     * @return array<mixed>
     */
    private function readModels(object $catalog): array
    {
        if (!method_exists($catalog, 'getModels')) {
            return [];
        }

        $models = $catalog->getModels();

        return is_array($models) ? $models : [];
    }

    /**
     * The catalogue entry declaring the most capabilities, used as the template for an unknown model.
     *
     * @param array<mixed> $models
     * @return array<string,mixed>
     */
    private function mostCapableEntry(array $models): array
    {
        $best = [];
        $bestCount = -1;
        foreach ($models as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $capabilities = $entry['capabilities'] ?? null;
            $count = is_array($capabilities) ? count($capabilities) : 0;
            if ($count > $bestCount) {
                /** @var array<string,mixed> $entry */
                $best = $entry;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Read a base URL out of a stored service row, falling back to the provider's own host.
     *
     * Rows saved before the field existed have no `base_url` at all, and a row saved with the
     * input cleared has an empty one; both mean "wherever the provider normally lives". The
     * trailing slash goes because the bridges append their own path to whatever they are given,
     * and a doubled separator is a 404 that reads like an authentication problem.
     *
     * @param array<string,mixed> $config Stored service configuration
     * @param string $default Provider's own base URL
     * @return string
     */
    private function resolveBaseUrl(array $config, string $default): string
    {
        $baseUrl = $config['base_url'] ?? null;
        $baseUrl = is_string($baseUrl) && trim($baseUrl) !== '' ? trim($baseUrl) : $default;

        return rtrim($baseUrl, '/');
    }
}
