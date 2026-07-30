<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiClientInterface;

/**
 * Adapter around a symfony/ai-platform Platform instance.
 *
 * The Symfony AI classes are referenced lazily (string FQCNs, guarded by
 * class_exists in ClientFactory) so this module does not hard-require
 * symfony/ai-platform. Written against symfony/ai-platform v0.11.0; the
 * component is experimental and not covered by Symfony's BC promise, so
 * pin the version and re-verify on upgrade.
 */
class SymfonyAiClient implements AiClientInterface
{
    /**
     * @param object $platform \Symfony\AI\Platform\PlatformInterface
     * @param string $model
     * @param string $serviceCode
     */
    public function __construct(
        private readonly object $platform,
        private readonly string $model,
        private readonly string $serviceCode,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function complete(string $prompt, array $options = []): string
    {
        $messageClass = \Symfony\AI\Platform\Message\Message::class;
        $messageBagClass = \Symfony\AI\Platform\Message\MessageBag::class;

        try {
            $messages = new $messageBagClass($messageClass::ofUser($prompt));

            return (string)$this->platform
                ->invoke($this->model, $messages, $options)
                ->asText();
        } catch (\Throwable $e) {
            throw new LocalizedException(
                __('AI request to service "%1" failed: %2', $this->serviceCode, $e->getMessage()),
                $e
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function getServiceCode(): string
    {
        return $this->serviceCode;
    }
}
