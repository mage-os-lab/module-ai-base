<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\HttpFetcher;

class Google implements AiServiceConfigurationInterface, ModelListProviderInterface
{
    use FieldFactoryTrait;
    use ModelListTrait;

    /**
     * Gemini API model listing endpoint.
     */
    private const MODELS_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * Largest page the listing endpoint serves, so one request usually returns the whole list.
     */
    private const PAGE_SIZE = 1000;

    /**
     * Upper bound on pages followed, so an endpoint that keeps handing out tokens cannot hold the
     * admin request open indefinitely.
     */
    private const MAX_PAGES = 10;

    /**
     * The generation method a model must support to be usable for chat.
     *
     * The listing also returns embedding, image and other models the chat client cannot drive;
     * this is the one field that tells them apart without a hardcoded name pattern.
     */
    private const CHAT_METHOD = 'generateContent';

    /**
     * Prefix the listing puts in front of every model id, which the bridge does not expect.
     */
    private const NAME_PREFIX = 'models/';

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     * @param HttpFetcher $modelListFetcher
     */
    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
        private readonly HttpFetcher $modelListFetcher,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'google';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Google Gemini';
    }

    /**
     * The fallback offered before a key is saved and the live list is refreshed.
     *
     * Google's moving `-latest` aliases rather than versioned ids, because Google retires versioned
     * Gemini models within months and a pinned fallback then fails Test Connection out of the box.
     *
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'gemini-pro-latest'        => 'Gemini Pro (latest)',
            'gemini-flash-latest'      => 'Gemini Flash (latest)',
            'gemini-flash-lite-latest' => 'Gemini Flash-Lite (latest)',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField($this->fieldFactory),
            $this->modelField($this->fieldFactory, $this->getSupportedModels()),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(array $configuration): array
    {
        $headers = ['x-goog-api-key' => $this->resolveApiKey($configuration)];
        $models = [];
        $pageToken = '';

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['pageSize' => self::PAGE_SIZE];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $response = $this->modelListFetcher->getJson(
                self::MODELS_URL . '?' . http_build_query($query),
                $headers
            );
            $models += $this->parseModelsPage($response);

            $nextToken = $response['nextPageToken'] ?? null;
            if (!is_string($nextToken) || $nextToken === '' || $nextToken === $pageToken) {
                break;
            }
            $pageToken = $nextToken;
        }

        return $models;
    }

    /**
     * Turn one page of the Gemini listing into a model id => label map, keeping chat models only.
     *
     * The shape is `{"models": [{"name": "models/<id>", "displayName": ..., "supportedGenerationMethods": [...]}]}`,
     * not the OpenAI-style `data` list the other providers share, so it gets its own parser.
     *
     * @param array<mixed> $response Decoded JSON response
     * @return array<string,string> Map of model id => label
     * @throws LocalizedException When the response does not contain a "models" list
     */
    private function parseModelsPage(array $response): array
    {
        if (!isset($response['models']) || !is_array($response['models'])) {
            throw new LocalizedException(
                __('Unexpected model list response from %1: missing "models" list.', $this->getName())
            );
        }

        $models = [];
        foreach ($response['models'] as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
                continue;
            }
            $methods = $entry['supportedGenerationMethods'] ?? null;
            if (!is_array($methods) || !in_array(self::CHAT_METHOD, $methods, true)) {
                continue;
            }
            $id = str_starts_with($entry['name'], self::NAME_PREFIX)
                ? substr($entry['name'], strlen(self::NAME_PREFIX))
                : $entry['name'];
            if ($id === '') {
                continue;
            }
            $label = $entry['displayName'] ?? null;
            $models[$id] = is_string($label) && $label !== '' ? $label : $id;
        }

        return $models;
    }
}
