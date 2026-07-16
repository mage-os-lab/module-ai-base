<?php

declare(strict_types=1);

namespace MageOS\AiBase\Controller\Adminhtml\Service;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\Storage;

/**
 * Live-fetches the model list of a configured AI service and persists it for the admin form.
 *
 * Extends Backend\App\Action so admin authentication, form-key validation and
 * ACL enforcement (via ADMIN_RESOURCE) apply through the standard plugins.
 */
class RefreshModels extends Action implements HttpPostActionInterface
{
    /**
     * Authorization resource, reuses the configuration ACL entry.
     */
    public const ADMIN_RESOURCE = 'MageOS_AiBase::configuration';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param AiServiceSelectorInterface $serviceSelector
     * @param Storage $modelListStorage
     * @param AiServiceConfigurationInterface[] $services Registered backends, same array the admin form block gets
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly Storage $modelListStorage,
        private readonly array $services = [],
    ) {
        parent::__construct($context);
    }

    /**
     * Refresh the model list for the requested service code and report the outcome as JSON.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $serviceCode = (string) $this->getRequest()->getParam('service_code');
        if ($serviceCode === '') {
            return $result->setData([
                'success' => false,
                'error' => (string) __('service_code is required'),
            ]);
        }

        try {
            $definition = $this->getServiceDefinition($serviceCode);
            if (!$definition instanceof ModelListProviderInterface) {
                return $result->setData([
                    'success' => false,
                    'error' => (string) __('Model list refresh is not supported for this service.'),
                ]);
            }

            $configured = $this->serviceSelector->getByCode($serviceCode);
            if ($configured === []) {
                return $result->setData([
                    'success' => false,
                    'error' => (string) __('No AI service configured for code "%1".', $serviceCode),
                ]);
            }

            $models = $definition->fetchModels($configured[0]->getConfiguration());
            $this->modelListStorage->save($serviceCode, $models);

            return $result->setData([
                'success' => true,
                'count' => count($models),
                'models' => $models,
            ]);
        } catch (LocalizedException $e) {
            return $result->setData([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'error' => (string) __('Model list refresh failed: %1', $e->getMessage()),
            ]);
        }
    }

    /**
     * Find the registered backend definition for a service code.
     *
     * @param string $serviceCode
     * @return AiServiceConfigurationInterface|null
     */
    private function getServiceDefinition(string $serviceCode): ?AiServiceConfigurationInterface
    {
        foreach ($this->services as $service) {
            if ($service instanceof AiServiceConfigurationInterface && $service->getCode() === $serviceCode) {
                return $service;
            }
        }

        return null;
    }
}
