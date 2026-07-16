# Mage-OS AI Base module

The goal of this module is to provide a way to allow to configure multiple AI backends.

## Installation

```bash
composer require mage-os/module-ai-base
php bin/magento module:enable MageOS_AiBase
```

You can find the new configuration option in System > Configuration > Services -> AI Configuration.

## Usage

If you have configured AI backends, you can fetch the configuration using these methods:

```php
use MageOS\AiBase\Api\AiServiceSelectorInterface;

AiServiceSelectorInterface::getAll(): array
AiServiceSelectorInterface::getByCode(string $code): array
```

Both methods return an array of `\MageOS\AiBase\Api\Data\AiServiceInterface` objects (multiple entries per code are possible because admins can register the same backend more than once).

```php
use MageOS\AiBase\Api\AiServiceSelectorInterface;

final class MyAiFunctionality
{
    public function __construct(
        private readonly AiServiceSelectorInterface $aiServiceSelector,
    ) {}

    public function doSomething(): void
    {
        $openAiServices = $this->aiServiceSelector->getByCode('openai');

        foreach ($openAiServices as $service) {
            $config = $service->getConfiguration();
            // $config = ['api_key' => '...', 'model' => 'gpt-4o', ...]
        }
    }
}
```

### Making AI calls

Instead of reading raw configuration, consumer modules can request a ready-to-use,
provider-agnostic client. The bundled implementation is backed by
[symfony/ai-platform](https://github.com/symfony/ai), which is a *soft* dependency —
install it only if you use the client layer:

```bash
composer require symfony/ai-platform
```

```php
use MageOS\AiBase\Api\AiClientFactoryInterface;

final class MyAiFunctionality
{
    public function __construct(
        private readonly AiClientFactoryInterface $aiClientFactory,
    ) {}

    public function doSomething(): string
    {
        // First configured service, or pass a code: create('openai')
        $client = $this->aiClientFactory->create();

        return $client->complete('Summarize this product description: ...');
    }
}
```

Provider bridges are mapped per service code in `etc/di.xml` (`platformFactories`
argument of `Model\Client\ClientFactory`); third-party modules can register additional
providers there, or replace the implementation entirely by preferencing
`AiClientFactoryInterface`.

### Credential encryption

Credential fields are encrypted at rest with Magento's `EncryptorInterface` when the
configuration is saved. A field is treated as a credential when its field descriptor
opts in via the `encrypted` option (`FieldDescriptorInterface::isEncrypted()`); the
bundled providers flag their `api_key` field. Third-party providers should pass
`'encrypted' => true` when building credential field descriptors — encrypted fields
are also always rendered as password inputs in the admin form. For rows whose provider
schema is not registered (e.g. the provider module was removed), fields named
`api_key`, `token`, `secret`, or the legacy `apikey` spelling are treated as
credentials as a fallback.
Values saved before encryption was introduced are detected and returned as-is, and are
re-encrypted the next time the configuration is saved in the admin.

In the admin form, stored credentials are displayed as an obscured `******` placeholder
instead of the real value. Saving the form without retyping a credential keeps the
previously stored value; entering a new value replaces it.

### Testing a connection

Each saved service row in the admin form shows a **Test Connection** button that sends a
minimal prompt to the provider and reports the latency and response (or the error) inline.
Only saved rows can be tested, because the client factory reads saved configuration; when
several rows share a service code, the first configured row of that code is used. The
feature relies on the client layer, so it requires `symfony/ai-platform` — if the library
is not installed, the error message shown by the button explains what to install.

### Refreshing model lists

Saved rows of services that support it also show a **Refresh Models** button. It fetches the
provider's current model list live (using the saved credentials) and updates the row's model
dropdown — refreshing is strictly manual; the module never fetches model lists automatically
or on a schedule. Supported by OpenAI, Anthropic, xAI (Grok), OpenRouter, Ollama and
LM Studio; other backends (e.g. Azure, whose listing endpoint is resource-specific) simply
don't show the button. The fetched list is stored per service code (with a fetched-at
timestamp) and keeps feeding the form until the next refresh; when nothing has been fetched
yet, the curated default model list built into each service remains the fallback. Third-party
providers can opt in by implementing `MageOS\AiBase\Api\ModelListProviderInterface` alongside
their service configuration class.

## Documentation

- [Provider Integration & Customization Guide](docs/PROVIDERS.md) — add a provider, wire a client bridge, opt into model refresh, customization recipes
- [Consumer Guide](docs/CONSUMING.md) — make AI calls from your module, handle failure modes, test your integration
- [Architecture](docs/ARCHITECTURE.md) — component map, data flows, storage formats, security model, design decisions

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and our [Code of Conduct](CODE_OF_CONDUCT.md).

## Security

Security issues: see [SECURITY.md](SECURITY.md). Please do **not** file public issues for vulnerabilities.

