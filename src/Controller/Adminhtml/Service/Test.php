<?php

declare(strict_types=1);

namespace MageOS\AiBase\Controller\Adminhtml\Service;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiClientFactoryInterface;

/**
 * Tests connectivity of a configured AI service by sending a minimal prompt.
 *
 * Extends Backend\App\Action so admin authentication, form-key validation and
 * ACL enforcement (via ADMIN_RESOURCE) apply through the standard plugins.
 */
class Test extends Action implements HttpPostActionInterface
{
    /**
     * Authorization resource, reuses the configuration ACL entry.
     */
    public const ADMIN_RESOURCE = 'MageOS_AiBase::configuration';

    /**
     * Maximum number of response characters returned to the admin form.
     */
    private const RESPONSE_SNIPPET_LENGTH = 100;

    /**
     * Minimal prompt used to verify the provider round-trip.
     */
    private const TEST_PROMPT = 'Reply with the single word: OK';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param AiClientFactoryInterface $clientFactory
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AiClientFactoryInterface $clientFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * Run a connectivity test for the requested service code and report the outcome as JSON.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $serviceCode = (string)$this->getRequest()->getParam('service_code');
        if ($serviceCode === '') {
            return $result->setData([
                'success' => false,
                'error' => (string)__('service_code is required'),
            ]);
        }

        try {
            $client = $this->clientFactory->create($serviceCode);
            $start = microtime(true);
            $response = $client->complete(self::TEST_PROMPT, ['max_tokens' => 16]);
            $latencyMs = (int)round((microtime(true) - $start) * 1000);

            return $result->setData([
                'success' => true,
                'latency_ms' => $latencyMs,
                'response' => mb_substr($response, 0, self::RESPONSE_SNIPPET_LENGTH),
            ]);
        } catch (LocalizedException $e) {
            return $result->setData([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'error' => (string)__('Connection test failed: %1', $e->getMessage()),
            ]);
        }
    }
}
