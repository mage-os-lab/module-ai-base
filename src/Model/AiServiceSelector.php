<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\AiServiceInterfaceFactory;

class AiServiceSelector implements AiServiceSelectorInterface
{
    private const CONFIG_PATH_AI_SERVICES = 'mageos_ai/services/configuration';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param AiServiceInterfaceFactory $aiServiceFactory
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AiServiceInterfaceFactory $aiServiceFactory,
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
        $raw = $this->scopeConfig->getValue(self::CONFIG_PATH_AI_SERVICES);
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
                'configuration' => $configuration,
            ]);
        }

        return $services;
    }
}
