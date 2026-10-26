<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

/**
 * A self-hosted opencode server (`opencode serve`), used as a model gateway.
 *
 * Not a hosted API: the server is opencode's agent, reached over its own session API, answering
 * through whichever providers it has configured. So the stored credential is the *server's*
 * password, not a provider key, and models are named `providerID/modelID` as the server routes
 * them. The bridge (`mage-os/library-ai-opencode-custom-platform`) takes that password first, like
 * every hosted bridge takes its key, and the address, login and agent by name through
 * `ClientFactory::ROW_ARGUMENTS`.
 */
class OpenCodeCustom extends AbstractAiService implements ModelListProviderInterface
{
    /**
     * Where `opencode serve` listens unless told otherwise.
     *
     * Repeated here rather than read off the bridge's constant, because the bridge is a soft
     * dependency and this class has to render the admin form on installs without it. From inside
     * a container, 127.0.0.1 is the container; the server then has to listen on 0.0.0.0 and be
     * addressed as e.g. http://host.docker.internal:4096.
     */
    public const DEFAULT_BASE_URL = 'http://127.0.0.1:4096';

    /**
     * The basic auth username the server expects unless `OPENCODE_SERVER_USERNAME` overrides it.
     */
    public const DEFAULT_USERNAME = 'opencode';

    /**
     * Stored field holding the server's basic auth username.
     */
    private const FIELD_USERNAME = 'username';

    /**
     * Stored field naming the agent the server should answer through.
     */
    private const FIELD_AGENT = 'agent';

    /**
     * The server's own catalogue: every configured provider with its models.
     */
    private const PROVIDERS_PATH = '/config/providers';

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     * @param JsonFetcherInterface $modelListFetcher
     */
    public function __construct(
        FieldDescriptorInterfaceFactory $fieldFactory,
        private readonly JsonFetcherInterface $modelListFetcher,
    ) {
        parent::__construct($fieldFactory);
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'opencode-custom';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'OpenCode Custom';
    }

    /**
     * @inheritdoc
     *
     * Empty: which models exist depends entirely on how the particular server is configured, so
     * there is no list that would be right for more than one install. Refresh Models asks the
     * server itself.
     */
    public function getSupportedModels(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     *
     * The password is stored under `api_key` so it is encrypted and masked exactly like every other
     * credential, and so the bridge receives it in the position every hosted bridge takes its key.
     * It may be left empty for a server started without `OPENCODE_SERVER_PASSWORD`.
     *
     * The agent is optional. The server's default agent carries opencode's coding-assistant prompt
     * and the AGENTS.md of the directory the server runs in; naming a plain agent defined in the
     * server's own configuration keeps both out of the answers.
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->baseUrlField(self::DEFAULT_BASE_URL),
            $this->fieldFactory->create([
                'name'    => self::FIELD_USERNAME,
                'label'   => 'Username',
                'type'    => FieldDescriptorInterface::TYPE_TEXT,
                'default' => self::DEFAULT_USERNAME,
            ]),
            $this->fieldFactory->create([
                'name'      => self::FIELD_API_KEY,
                'label'     => 'Server Password',
                'type'      => FieldDescriptorInterface::TYPE_PASSWORD,
                'encrypted' => true,
            ]),
            $this->fieldFactory->create([
                'name'  => self::FIELD_AGENT,
                'label' => 'Agent',
                'type'  => FieldDescriptorInterface::TYPE_TEXT,
            ]),
            $this->freeTextModelField(),
        ];
    }

    /**
     * @inheritdoc
     *
     * Reads `GET /config/providers`, which lists every provider the server has configured and the
     * models each serves, and returns them as the `providerID/modelID` the bridge routes by.
     */
    public function fetchModels(array $configuration): array
    {
        $baseUrl = $this->resolveBaseUrl($configuration, self::DEFAULT_BASE_URL);
        $response = $this->modelListFetcher->getJson(
            $baseUrl . self::PROVIDERS_PATH,
            $this->authHeaders($configuration),
        );

        $providers = $response['providers'] ?? null;
        if (!is_array($providers)) {
            throw new LocalizedException(
                __('Unexpected model list response from %1: missing "providers" list.', $this->getName())
            );
        }

        $models = [];
        foreach ($providers as $provider) {
            if (!is_array($provider) || !is_string($provider['id'] ?? null) || !is_array($provider['models'] ?? null)) {
                continue;
            }
            $models += $this->providerModels(
                $provider['id'],
                $this->displayName($provider, $provider['id']),
                $provider['models'],
            );
        }

        return $models;
    }

    /**
     * One provider's models as `providerID/modelID` => "Provider: Model".
     *
     * The listing keys each model by id and repeats the id inside the entry; the entry wins when
     * present, the key stands in when it is not, and an entry without either is skipped.
     *
     * @param string $providerId
     * @param string $providerName
     * @param array<mixed> $entries
     * @return array<string,string>
     */
    private function providerModels(string $providerId, string $providerName, array $entries): array
    {
        $models = [];
        foreach ($entries as $key => $entry) {
            $modelId = is_array($entry) && is_string($entry['id'] ?? null) ? $entry['id'] : (string) $key;
            if ($modelId === '') {
                continue;
            }
            $models[$providerId . '/' . $modelId] = $providerName . ': ' . $this->displayName($entry, $modelId);
        }

        return $models;
    }

    /**
     * The `name` of a listing entry, or the id when the server gave none.
     *
     * @param mixed $entry
     * @param string $id
     * @return string
     */
    private function displayName(mixed $entry, string $id): string
    {
        $name = is_array($entry) ? ($entry['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : $id;
    }

    /**
     * Basic auth for the model listing, the same credentials the bridge uses for prompts.
     *
     * Only sent when a password is stored; a server without one needs none, and one with a password
     * answers 401 to the listing just as it would to a prompt, which HttpFetcher reports as a
     * rejected credential.
     *
     * @param array<string,mixed> $configuration
     * @return array<string,string>
     */
    private function authHeaders(array $configuration): array
    {
        $password = $this->stringValue($configuration, self::FIELD_API_KEY);
        if ($password === '') {
            return [];
        }

        $username = trim($this->stringValue($configuration, self::FIELD_USERNAME));
        $login = ($username !== '' ? $username : self::DEFAULT_USERNAME) . ':' . $password;

        return ['Authorization' => 'Basic ' . base64_encode($login)];
    }
}
