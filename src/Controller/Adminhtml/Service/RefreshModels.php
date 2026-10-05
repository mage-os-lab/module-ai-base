<?php

declare(strict_types=1);

namespace MageOS\AiBase\Controller\Adminhtml\Service;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\FailureReporter;
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
     * @param FailureReporter $failureReporter Logs a failure in full and decides what the page shows
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly Storage $modelListStorage,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly FailureReporter $failureReporter,
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
        $serviceCode = $this->getRequestedParam('service_code');
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

            $configured = $this->resolveRow($this->getRequestedParam('service_id'), $serviceCode);
            if ($configured === null) {
                return $result->setData([
                    'success' => false,
                    'error' => (string) __('No AI service configured for code "%1".', $serviceCode),
                ]);
            }

            $models = $definition->fetchModels($configured->getConfiguration());
            $this->modelListStorage->save($serviceCode, $models);

            return $result->setData([
                'success' => true,
                'count' => count($models),
                'models' => $models,
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'error' => $this->failureReporter->report(
                    __('Model list refresh failed'),
                    $e,
                    ['service_id' => $this->getRequestedParam('service_id'), 'service_code' => $serviceCode],
                ),
            ]);
        }
    }

    /**
     * The configured row whose credentials the list is fetched with.
     *
     * Model lists are per provider, but the key that fetches one belongs to a row. An administrator
     * with two rows of the same provider, which is the setup row ids exist for, would otherwise
     * refresh from the first row's account no matter which button they pressed, and read the
     * resulting error against the key in front of them.
     *
     * @param string $serviceId
     * @param string $serviceCode
     * @return AiServiceInterface|null
     */
    private function resolveRow(string $serviceId, string $serviceCode): ?AiServiceInterface
    {
        if ($serviceId !== '') {
            return $this->serviceSelector->getById($serviceId);
        }

        return $this->serviceSelector->getByCode($serviceCode)[0] ?? null;
    }

    /**
     * A request parameter, ignoring one that did not arrive as a string.
     *
     * Query parameters are whatever the caller put in the URL: `?service_code[]=x` arrives as an
     * array, and casting that hands the string `Array` to the registry as if it were a code.
     *
     * @param string $name
     * @return string
     */
    private function getRequestedParam(string $name): string
    {
        $value = $this->getRequest()->getParam($name);

        return is_string($value) ? $value : '';
    }
}
