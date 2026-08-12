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
use MageOS\AiBase\Api\AiClientInterface;

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
     * Run a connectivity test for the requested row and report the outcome as JSON.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $serviceId = $this->getRequestedParam('service_id');
        $serviceCode = $this->getRequestedParam('service_code');
        if ($serviceId === '' && $serviceCode === '') {
            return $result->setData([
                'success' => false,
                'error' => (string)__('service_id is required'),
            ]);
        }

        try {
            $client = $this->resolveClient($serviceId, $serviceCode);
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

    /**
     * The client for the row the button belongs to.
     *
     * The row id is what the form sends, because a service code cannot address a row: an
     * administrator with two rows of the same provider, which is the setup row ids exist for, would
     * otherwise test the first row's credentials from the second row's button and be told the key
     * they are looking at works. The code remains as a fallback for a caller that has only that.
     *
     * @param string $serviceId
     * @param string $serviceCode
     * @return AiClientInterface
     * @throws LocalizedException
     */
    private function resolveClient(string $serviceId, string $serviceCode): AiClientInterface
    {
        return $serviceId !== ''
            ? $this->clientFactory->createById($serviceId)
            : $this->clientFactory->create($serviceCode);
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
