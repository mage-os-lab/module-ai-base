# Consumer Guide

How to use `MageOS_AiBase` from another module — making AI calls, reading configuration,
and handling failure. Audience: developers of modules that *use* AI services (product
description generation, translations, chat, ...). To *add* a provider, see
[PROVIDERS.md](PROVIDERS.md).

## Declare the dependency

```json
// composer.json
"require": { "mage-os/module-ai-base": "^1.0" }
```

```xml
<!-- etc/module.xml -->
<sequence><module name="MageOS_AiBase"/></sequence>
```

Type-hint only against `MageOS\AiBase\Api\*` interfaces. Never depend on `Model\*` classes
or on symfony/ai types — implementations can be swapped by the host store via `<preference>`.

## Making AI calls (recommended)

```php
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiClientFactoryInterface;

class DescriptionGenerator
{
    public function __construct(
        private readonly AiClientFactoryInterface $aiClientFactory,
    ) {
    }

    public function generate(string $productName): string
    {
        $client = $this->aiClientFactory->create();          // first configured service
        // ...or a specific backend: $this->aiClientFactory->create('anthropic');

        return $client->complete(
            'Write a product description for: ' . $productName,
            ['max_tokens' => 400],                            // provider options, passed through
        );
    }
}
```

`complete()` is single-turn prompt-in/text-out. `getServiceCode()` tells you which backend
served the client (useful for logging/attribution).

### Failure modes to handle

`create()` and `complete()` throw `LocalizedException` with admin-readable messages:

| Condition | When |
|---|---|
| No service configured (at all, or for the requested code) | `create()` |
| No client bridge registered for the service code | `create()` |
| symfony/ai-platform not installed | `create()` |
| Provider/API call failed (auth, network, provider error) | `complete()` |

Treat all of these as recoverable: catch `LocalizedException`, degrade gracefully (skip the
AI feature, queue for retry, surface the message to the admin). Don't let an unconfigured
AI backend break checkout or a cron run. The messages are actionable by design — the
"not installed" one includes the composer command — so surfacing them in admin UIs is
usually the right move.

### Which service will `create()` use?

- `create()` (no argument): the **first configured service overall**, in the order the
  admin saved them.
- `create('openai')`: the **first configured row** with that code. Admins can configure
  the same backend multiple times; if you need a specific instance (e.g. per-purpose keys),
  read the rows yourself via the selector and pick before calling.

## Reading raw configuration (lower level)

When you need credentials/values directly — e.g. you're calling a provider API the client
layer doesn't cover:

```php
use MageOS\AiBase\Api\AiServiceSelectorInterface;

$services = $this->aiServiceSelector->getByCode('openai');   // AiServiceInterface[]
foreach ($services as $service) {
    $config = $service->getConfiguration();
    // ['api_key' => '...', 'model' => 'gpt-4o', ...] — credentials already decrypted
}
```

Notes:

- Values are decrypted for you; **never log or persist them**, and never echo them to any
  frontend or admin response.
- Field names are snake_case: `api_key`, `model`, `base_url`, `endpoint`, `api_version`.
- An empty array means nothing is configured — expected state on fresh installs; handle it.
- Configuration is read with store scope, so per-store setups resolve automatically from
  the current store context.

## Extending behavior

Both consumer interfaces are DI-served, so standard Magento extension applies:

- **Plugin** on `AiClientInterface::complete()` for logging, token accounting, redaction,
  or prompt policy — remember plugins see prompts and responses, treat them as sensitive.
- **Preference** on `AiClientFactoryInterface` to substitute the entire client stack.

## Testing your consumer

Both interfaces mock cleanly with PHPUnit:

```php
$client = $this->createMock(AiClientInterface::class);
$client->method('complete')->willReturn('A fine description.');
$factory = $this->createMock(AiClientFactoryInterface::class);
$factory->method('create')->willReturn($client);
```

Also test the unconfigured path: `$factory->method('create')->willThrowException(
new LocalizedException(__('No AI service configured')))` — your feature should degrade,
not fatal.
