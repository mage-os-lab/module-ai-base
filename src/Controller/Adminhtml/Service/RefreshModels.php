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
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\Storage;
use MageOS\AiBase\Model\ServiceRegistry;

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
     * @param ServiceRegistry $serviceRegistry Registered backends, the same set the admin form gets
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly Storage $modelListStorage,
        private readonly ServiceRegistry $serviceRegistry,
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
        $serviceCode = $this->getRequestedServiceCode();
        if ($serviceCode === '') {
            return $result->setData([
                'success' => false,
                'error' => (string) __('service_code is required'),
            ]);
        }

        try {
            $definition = $this->serviceRegistry->get($serviceCode);
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
     * The service code the request asked for, ignoring a parameter that did not arrive as a string.
     *
     * Query parameters are whatever the caller put in the URL: `?service_code[]=x` arrives as an
     * array, and casting that hands the string `Array` to the registry as if it were a code.
     *
     * @return string
     */
    private function getRequestedServiceCode(): string
    {
        $serviceCode = $this->getRequest()->getParam('service_code');

        return is_string($serviceCode) ? $serviceCode : '';
    }
}
