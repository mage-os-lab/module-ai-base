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
            // $config = ['apikey' => '...', 'model' => 'gpt-4o', ...]
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

API keys and other credential fields (`apikey`, `api_key`, `token`, `secret`) are
encrypted at rest with Magento's `EncryptorInterface` when the configuration is saved.
Values saved before encryption was introduced are detected and returned as-is, and are
re-encrypted the next time the configuration is saved in the admin.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and our [Code of Conduct](CODE_OF_CONDUCT.md).

## Security

Security issues: see [SECURITY.md](SECURITY.md). Please do **not** file public issues for vulnerabilities.

