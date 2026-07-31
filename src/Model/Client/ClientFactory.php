<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Exception\LocalizedException;
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

        return $this->clientFactory->create([
            'platform' => $this->createPlatform($service),
            'model' => (string)($service->getConfiguration()['model'] ?? ''),
            'serviceCode' => $service->getCode(),
        ]);
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
                    . 'Configure one under Stores > Configuration > Services > AI Configuration.',
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
                    . 'Configure one under Stores > Configuration > Services > AI Configuration.'
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
     * @return object
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
        return match ($code) {
            'ollama' => $factoryClass::createPlatform($config['base_url'] ?? null),
            'lmstudio' => $factoryClass::createPlatform($config['base_url'] ?? 'http://localhost:1234'),
            'azure' => $factoryClass::createPlatform(
                $config['endpoint'] ?? '',
                $config['deployment'] ?? $config['model'] ?? '',
                $config['api_version'] ?? '2024-10-21',
                $config['api_key'] ?? ''
            ),
            default => $factoryClass::createPlatform($config['api_key'] ?? ''),
        };
    }
}
