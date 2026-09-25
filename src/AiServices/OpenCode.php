<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\HttpFetcher;

class OpenCode implements AiServiceConfigurationInterface, ModelListProviderInterface
{
    use FieldFactoryTrait;
    use ModelListTrait;

    /**
     * Host and path prefix of the hosted OpenCode Zen gateway.
     *
     * Repeated here rather than read off the bridge's own constant, because the bridge is a soft
     * dependency: this class describes and stores configuration on installs that never install it,
     * and naming its constant would make the admin form fatal instead of degrading.
     */
    public const DEFAULT_BASE_URL = 'https://opencode.ai/zen';

    /**
     * Path of the model listing, relative to the base URL.
     *
     * Public and unauthenticated on the hosted gateway, which is why the Authorization header below
     * is conditional: an administrator can populate the model list before pasting a key.
     */
    private const MODELS_PATH = '/v1/models';

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
        return 'opencode-zen';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'OpenCode Zen';
    }

    /**
     * @inheritdoc
     *
     * Only the models Zen serves from its Chat Completions endpoint, which is the one the bridge
     * speaks. Zen also fronts GPT, Claude, Gemini and Grok, from three other endpoints with three
     * other request shapes; listing them here would offer an administrator a model that saves
     * cleanly and then fails on the first call.
     *
     * The paid families only. Zen's free and stealth models are explicitly limited-time, so a
     * curated copy of them is stale by design; they arrive through Refresh Models instead, which
     * reads the gateway's live listing.
     */
    public function getSupportedModels(): array
    {
        return [
            'deepseek-v4-pro'     => 'DeepSeek V4 Pro',
            'deepseek-v4-flash'   => 'DeepSeek V4 Flash',
            'deepseek-v4.1-flash' => 'DeepSeek V4.1 Flash',
            'minimax-m3'          => 'MiniMax M3',
            'minimax-m2.7'        => 'MiniMax M2.7',
            'glm-5.3'             => 'GLM 5.3',
            'glm-5.3-flash'       => 'GLM 5.3 Flash',
            'kimi-k3'             => 'Kimi K3',
            'kimi-k2.7-code'      => 'Kimi K2.7 Code',
        ];
    }

    /**
     * @inheritdoc
     *
     * The model field is free text rather than a select built from getSupportedModels(): Zen is a
     * gateway whose catalogue turns over monthly, and the curated list above is a starting point,
     * not a constraint. It reaches the form as autocomplete suggestions, and Refresh Models
     * replaces them with whatever the gateway currently serves.
     *
     * The base URL is offered because the same wire format is what a proxy in front of Zen, or a
     * gateway an organisation runs itself, exposes. Left untouched it is the hosted gateway.
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField($this->fieldFactory),
            $this->baseUrlField($this->fieldFactory, self::DEFAULT_BASE_URL),
            $this->freeTextModelField($this->fieldFactory),
        ];
    }

    /**
     * @inheritdoc
     *
     * Returns Zen's whole catalogue, including the models this module's bridge cannot reach. That
     * is deliberate: it is what the gateway serves, and an administrator comparing this list with
     * the gateway's own documentation should see the same thing. Filtering it here would need a
     * hardcoded list of which family lives behind which endpoint, which is exactly the stale local
     * copy the live listing exists to avoid.
     */
    public function fetchModels(array $configuration): array
    {
        $headers = [];
        $apiKey = $this->resolveApiKey($configuration);
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $baseUrl = $this->resolveBaseUrl($configuration, self::DEFAULT_BASE_URL);
        $response = $this->modelListFetcher->getJson($baseUrl . self::MODELS_PATH, $headers);

        return $this->parseDataModelList($response);
    }
}
