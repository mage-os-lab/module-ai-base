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
AiServiceSelectorInterface::getById(string $id): ?AiServiceInterface
```

`getAll()` and `getByCode()` return an array of `\MageOS\AiBase\Api\Data\AiServiceInterface` objects (multiple entries per code are possible because admins can register the same backend more than once); `getById()` returns the single row with that id, or `null` once the admin deletes it.

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
[symfony/ai-platform](https://github.com/symfony/ai), which is a *soft* dependency:
install it only if you use the client layer:

```bash
composer require symfony/ai-platform
```

> **symfony/ai-platform is experimental.** Experimental features are not covered by Symfony's
> [Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html).
>
> `MageOS\AiBase\Api\*` is this module's own contract and is insulated from that: when Symfony
> changes, the adapter behind these interfaces absorbs it. Code written against symfony/ai types
> directly (see [the escape hatch](#reaching-the-platform-directly)) is not insulated, and has to
> be re-verified on every symfony/ai-platform upgrade. Pin the version either way.

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

### Letting the admin pick a service

Consumer modules do not have to hardcode a service code. Point a `select` field in your own
`system.xml` at the option source this module ships, and every configured service shows up in it:

```xml
<field id="ai_service" translate="label" type="select" sortOrder="10"
       showInDefault="1" showInWebsite="1" showInStore="1">
    <label>AI service</label>
    <source_model>MageOS\AiBase\Model\Config\Source\ConfiguredService</source_model>
</field>
```

Options are labelled by provider and model (`OpenAI (gpt-4o)`); a row whose Symfony AI bridge is
not installed stays selectable but says so. Use
`MageOS\AiBase\Model\Config\Source\ConfiguredServiceWithAutomatic` instead to prepend an
empty-valued *Automatic (first usable service)* option.

The stored value is the row id, because the service code cannot tell two rows of the same
provider apart. Resolve it back with either of:

```php
$this->aiClientFactory->createById($serviceId);       // ready-to-use client
$this->aiServiceSelector->getById($serviceId);        // raw configuration, or null when deleted
```

See [docs/CONSUMING.md](docs/CONSUMING.md) for the full example.

### Conversations, tools and streaming

`complete()` is the single-turn convenience. For anything more, `chat()` takes a conversation
and returns text, requested tool calls, token counts and the stop reason, and `streamChat()`
returns a generator of typed chunks:

```php
$request = $this->chatRequestBuilderFactory->create()
    ->withSystemMessage('You are a Magento support assistant.')
    ->withUserMessage($question)
    ->withTool('get_orders', 'Lists orders by status', $schema)
    ->build();

$response = $client->chat($request);
$response->getText();
$response->getToolCalls();
$response->getUsage();
$response->getFinishReason();     // normalized across providers; Length means truncated

$stream = $client->streamChat($request);
foreach ($stream as $chunk) {
    // StreamChunkType::Text | Thinking | ToolCall | Usage
}
$turn = $stream->getReturn();     // the assembled ChatResponseInterface, ready to append
```

This module never executes tools. It reports what the model asked for; you run it and feed the
result back with `ChatRequestInterface::withToolResult()`, after putting the model's own turn
back with `withAssistantTurn()`. Streamed tool calls arrive complete, with arguments already
decoded, so there is no SSE parsing to do. Full example with the tool loop:
[docs/CONSUMING.md](docs/CONSUMING.md).

The four options every provider has (`max_tokens`, `temperature`, `top_p`, `stop`) are
translated to whatever the configured backend calls them, so moving a workload between
providers does not silently change the cap it runs under. Anything else passes through
untouched.

Provider bridges are registered per service code in `etc/di.xml` (`bridges` argument of
`Model\Client\BridgeRegistry`); third-party modules can register additional providers there,
or replace the implementation entirely by preferencing `AiClientFactoryInterface`.

### Reaching the platform directly

symfony/ai-platform does much more than chat and streaming: executed tool loops via
`symfony/ai-agent`, message stores via `symfony/ai-chat`, structured output, embeddings, vector
stores, image and audio. Rather than mirror all of that, this module hands over the platform it
already built for you, with credentials resolved and the right bridge selected:

```php
use MageOS\AiBase\Api\PlatformAwareInterface;

$client = $this->aiClientFactory->createById($serviceId);

if ($client instanceof PlatformAwareInterface) {
    $result = $client->getPlatform()->invoke(
        $client->getModel(),
        $messageBag,
        $client->normalizeOptions(['max_tokens' => 400]),   // keeps the option translation
    );
}
```

The `instanceof` check is the point: it makes the coupling deliberate, and a store that
preferences its own client stack simply does not implement the interface.

**Everything past `getPlatform()` is outside this module's compatibility promise**, for the
reason in the note above. `normalizeOptions()` is offered separately because calling the platform
directly otherwise opts you out of the option translation too, and that is the piece most worth
keeping. Full example, including a `symfony/ai-agent` loop:
[docs/CONSUMING.md](docs/CONSUMING.md).

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
is not installed, the error message shown by the button names the exact package to install.

### Refreshing model lists

Saved rows of services that support it also show a **Refresh Models** button. It fetches the
provider's current model list live (using the saved credentials) and updates the row's model
field — refreshing is strictly manual; the module never fetches model lists automatically
or on a schedule. Where the model field is a dropdown (OpenAI, Anthropic) the fetched
list replaces its options. Where it is free text because the catalogue cannot be known ahead
of time (OpenRouter, and self-hosted Ollama and LM Studio) the list is offered as autocomplete
suggestions, so you can still type a model the provider has not listed. Other backends
(e.g. Azure, whose listing endpoint is resource-specific) simply
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

