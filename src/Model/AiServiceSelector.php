<?php

namespace MageOS\AiBase\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\AiServiceInterfaceFactory;

class AiServiceSelector implements AiServiceSelectorInterface
{
    private const CONFIG_PATH_AI_SERVICES = 'mageos_ai/services/configuration';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AiServiceInterfaceFactory $aiServiceFactory,
    ) {}

    public function getAll(): array
    {
        return $this->getParsedConfig();
    }

    public function getByCode(string $code): array
    {
        $services = $this->getParsedConfig();

        return array_filter($services, fn(AiServiceInterface $service) => $service->getCode() === $code);
    }

    private function getParsedConfig(): array
    {
        $json = json_decode($this->scopeConfig->getValue(self::CONFIG_PATH_AI_SERVICES), true);
        if ($json === null) {
            return [];
        }

        return array_map( function(array $item) {
            $service = array_first(array_keys($item));

            return $this->aiServiceFactory->create([
                'code' => $service,
                'configuration' => $item[$service]
            ]);
        }, $json);
    }
}
