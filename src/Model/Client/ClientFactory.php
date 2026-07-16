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
     * @param array $platformFactories Service code => Symfony AI bridge PlatformFactory FQCN
     */
    public function __construct(
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly SymfonyAiClientFactory $clientFactory,
        private readonly array $platformFactories = [],
    ) {
    }

    /**
     * @inheritdoc
     */
    public function create(?string $serviceCode = null): AiClientInterface
    {
        $services = $serviceCode === null
            ? $this->serviceSelector->getAll()
            : $this->serviceSelector->getByCode($serviceCode);

        $service = $services[0] ?? null;
        if (!$service instanceof AiServiceInterface) {
            throw new LocalizedException(
                __(
                    'No AI service configured%1. '
                    . 'Configure one under Stores > Configuration > Services > AI Configuration.',
                    $serviceCode !== null ? __(' for code "%1"', $serviceCode) : '',
                )
            );
        }

        return $this->clientFactory->create([
            'platform' => $this->createPlatform($service),
            'model' => (string)($service->getConfiguration()['model'] ?? ''),
            'serviceCode' => $service->getCode(),
        ]);
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
        $factoryClass = $this->platformFactories[$code] ?? null;
        if ($factoryClass === null) {
            throw new LocalizedException(
                __('No Symfony AI platform bridge registered for service "%1".', $code)
            );
        }
        if (!class_exists($factoryClass) || !method_exists($factoryClass, 'createPlatform')) {
            throw new LocalizedException(
                __(
                    'The Symfony AI bridge for "%1" is not installed. '
                    . 'Run "composer require symfony/ai-platform" (plus the provider bridge package, if separate).',
                    $code
                )
            );
        }

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
