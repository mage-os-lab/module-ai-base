<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\AiServiceInterfaceFactory;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;

class AiServiceSelector implements AiServiceSelectorInterface
{
    private const CONFIG_PATH_AI_SERVICES = 'mageos_ai/services/configuration';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param AiServiceInterfaceFactory $aiServiceFactory
     * @param SensitiveDataProcessor $sensitiveDataProcessor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AiServiceInterfaceFactory $aiServiceFactory,
        private readonly SensitiveDataProcessor $sensitiveDataProcessor,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getAll(): array
    {
        return $this->getParsedConfig();
    }

    /**
     * @inheritdoc
     */
    public function getByCode(string $code): array
    {
        return array_values(array_filter(
            $this->getParsedConfig(),
            fn (AiServiceInterface $service) => $service->getCode() === $code,
        ));
    }

    /**
     * Read and defensively parse the stored services configuration.
     *
     * @return AiServiceInterface[]
     */
    private function getParsedConfig(): array
    {
        $raw = $this->scopeConfig->getValue(self::CONFIG_PATH_AI_SERVICES, ScopeInterface::SCOPE_STORE);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $services = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $code = array_key_first($row);
            $configuration = $row[$code];
            if (!is_string($code) || !is_array($configuration)) {
                continue;
            }
            $services[] = $this->aiServiceFactory->create([
                'code' => $code,
                'configuration' => $this->sensitiveDataProcessor->decryptRow($configuration),
            ]);
        }

        return $services;
    }
}
