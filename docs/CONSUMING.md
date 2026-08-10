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
Everything below follows that rule: requests are assembled through
`Api\ChatRequestBuilderInterface`, and every `Api\Data` interface has a `<preference>`, so the
Magento-generated `*InterfaceFactory` for it resolves if you'd rather build one directly.

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
            ['max_tokens' => 400],                            // see "Options" below
        );
    }
}
```

`complete()` is single-turn prompt-in/text-out, a convenience over `chat()`.
A client also says what it is, for logging and cost attribution: `getServiceCode()` (`openai`),
`getServiceId()` (the configured row, which is what separates two accounts on the same backend)
and `getModel()` (`gpt-4o`). Cost is per model, so a usage log wants all three.

## Options

Four options are provider-neutral and get translated to whatever the configured backend calls
them, so the same code works whichever one an administrator picked:

| Option | Notes |
|---|---|
| `max_tokens` | `max_output_tokens` on OpenAI/Azure, `maxOutputTokens` on Google, `num_predict` on Ollama. Anthropic *requires* it; the client sends 4096 when you don't. |
| `temperature` | Same name everywhere. |
| `top_p` | `topP` on Google. |
| `stop` | `stop_sequences` on Anthropic, `stopSequences` on Google; a single string is wrapped into a list where the provider wants one. **Not supported on OpenAI and Azure**, whose Responses API has no such parameter — passing it there throws rather than being dropped. |

Anything else is passed through to the provider untouched, so provider-specific features stay
reachable (Anthropic's `thinking`, Ollama's `keep_alive`, ...). That is the escape hatch for code
that has deliberately picked its backend; it is not portable, by definition.

## Conversations, tools and streaming

Build a request with `ChatRequestBuilderInterface`. Inject its generated factory and start one
per call — every method returns a new builder, so a builder holding your common preamble can be
kept and branched from:

```php
use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\ChatRequestBuilderInterfaceFactory;

public function __construct(
    private readonly AiClientFactoryInterface $aiClientFactory,
    private readonly ChatRequestBuilderInterfaceFactory $chatRequestBuilderFactory,
) {
}

$request = $this->chatRequestBuilderFactory->create()
    ->withSystemMessage('You are a Magento support assistant.')
    ->withUserMessage('Which orders are still pending?')
    ->withTool('get_orders', 'Lists orders by status', [
        'type' => 'object',
        'properties' => ['status' => ['type' => 'string']],
    ])
    ->build();

$response = $this->aiClientFactory->create()->chat($request);
$response->getText();             // assistant text, empty when it only asked for tools
$response->getToolCalls();        // ToolCallInterface[]
$response->getUsage();            // prompt/completion/total tokens, or null
$response->getFinishReason();     // FinishReason enum, or null
```

`getFinishReason()` is normalized across providers, because the same event is `length` at
OpenAI, `max_tokens` at Anthropic and `MAX_TOKENS` at Google. `FinishReason::Length` is the one
worth handling everywhere — it means the text is a truncated answer rather than a finished one,
and nothing else in the response says so:

```php
if ($response->getFinishReason() === FinishReason::Length) {
    // the answer is cut off: raise max_tokens, or ask for something shorter
}
```

`getRawFinishReason()` keeps the provider's own wording, for logs and support tickets.

> **One reserved pair of argument names.** A tool argument may not be named `instance` or
> `argument`. The schema reaches the tool definition through a Magento-generated factory, and the
> ObjectManager walks array arguments looking for DI references: a nested object holding an
> `instance` key is resolved as a service (raising a `TypeError`), and one holding an `argument`
> key is replaced by a global argument (silently nulling the surrounding object). Every other
> name, at every nesting level, passes through untouched. Rename the argument, or build the
> definition yourself and pass it to `withToolDefinition()` — an already-built object is not
> walked.

### The tool loop

**This module never executes tools.** It reports what the model asked for and carries your
result back. Whether a call may run is your policy (user confirmation for write actions, a
Magento ACL per tool, per-tool instructions), and that does not generalise.

```php
for ($i = 0; $i < $maxIterations; $i++) {
    $response = $client->chat($request);

    if (!$response->hasToolCalls()) {
        return $response->getText();
    }

    $request = $request->withAssistantTurn($response);

    foreach ($response->getToolCalls() as $call) {
        // your ACL check, your confirmation gate, your tool registry
        $result = $this->myToolRegistry->run($call->getName(), $call->getArguments());
        $request = $request->withToolResult($call, json_encode($result));
    }
}
```

`ChatRequestInterface` is immutable, so each iteration gets a fresh request and nothing leaks
into one a caller still holds. Two methods carry the two halves of a turn that are easy to get
wrong: `withAssistantTurn()` puts the model's own message back with its tool calls attached
(append the text and forget the calls, and the provider rejects results answering calls it
cannot see), and `withToolResult()` binds each result to the call that produced it.

### Streaming

`streamChat()` returns a `\Generator`, so you drive the loop and may stop early:

```php
foreach ($client->streamChat($request) as $chunk) {
    match ($chunk->getType()) {
        StreamChunkType::Text     => $this->emit($chunk->getText()),
        StreamChunkType::Thinking => null,                    // reasoning, not the answer
        StreamChunkType::ToolCall => $calls[] = $chunk->getToolCall(),
        StreamChunkType::Usage    => $usage = $chunk->getUsage(),
    };
}
```

Bridging to a callback-style stream is three lines, since `getData()` is a flat payload:

```php
foreach ($client->streamChat($request) as $chunk) {
    $onChunk($chunk->getType()->value, $chunk->getData());
}
```

Tool calls arrive **complete**, with arguments already accumulated and JSON-decoded by the
provider bridge. There are no SSE frames to parse and no `input_json_delta` fragments to
stitch together. A turn requesting several tools yields one chunk per call, so
`getToolCall()` always means exactly one.

#### The turn a stream produced

When the stream runs to completion the generator **returns** the same `ChatResponseInterface`
a buffered `chat()` would have given you — text concatenated, tool calls collected, final token
counts and stop reason attached. A streaming tool loop needs exactly that to append before the
next iteration, so there is no accumulator to write:

```php
$stream = $client->streamChat($request);

foreach ($stream as $chunk) {
    if ($chunk->getType() === StreamChunkType::Text) {
        $this->emit($chunk->getText());
    }
}

$turn = $stream->getReturn();            // ChatResponseInterface
$request = $request->withAssistantTurn($turn);

foreach ($turn->getToolCalls() as $call) {
    $request = $request->withToolResult($call, json_encode($this->myToolRegistry->run(
        $call->getName(),
        $call->getArguments(),
    )));
}
```

`getReturn()` is only valid once the generator has finished. Breaking out of the loop early
raises an `\Exception` from PHP, which is the correct signal: there is no complete turn to
append.

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

- `create()` (no argument): the **first configured service whose bridge is installed**, in
  the order the admin saved them.
- `create('openai')`: the **first configured row** with that code. Admins can configure
  the same backend multiple times; to reach a specific row, let the admin pick one and use
  `createById()` (below).
- `createById('_1712345678901_901')`: the **one row** carrying that id, whichever position
  it occupies.

## Letting the admin pick a service

Rather than hardcoding a service code, give your module's own configuration a select field
backed by this module's option source. It lists every service the admin configured, labelled
by provider and model:

```xml
<!-- your module's etc/adminhtml/system.xml -->
<field id="ai_service" translate="label" type="select" sortOrder="10"
       showInDefault="1" showInWebsite="1" showInStore="1">
    <label>AI service</label>
    <source_model>MageOS\AiBase\Model\Config\Source\ConfiguredService</source_model>
</field>
```

Two source models are available:

| Source model | Options |
|---|---|
| `Model\Config\Source\ConfiguredService` | One per configured row |
| `Model\Config\Source\ConfiguredServiceWithAutomatic` | The same, preceded by an empty-valued *Automatic (first usable service)* option |

Labels read `OpenAI (gpt-4o)`. Two rows that would otherwise be identical are numbered
(`OpenAI (gpt-4o) #2`). A row whose Symfony AI bridge is missing stays selectable but says so
(`Ollama (llama3, bridge not installed)`), because a module calling the provider with its own
HTTP client does not need a bridge.

The **stored value is the row id**, not the service code, since the code cannot tell two rows
of the same provider apart. Turn that stored id into a client with `createById()`:

```php
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiBase\Api\AiClientFactoryInterface;

$serviceId = (string) $this->scopeConfig->getValue('my_module/ai/ai_service', ScopeInterface::SCOPE_STORE);

$client = $serviceId === ''
    ? $this->aiClientFactory->create()            // "Automatic", or nothing chosen yet
    : $this->aiClientFactory->createById($serviceId);
```

Or read its raw configuration with `AiServiceSelectorInterface::getById()`, which returns
`null` when the admin has since deleted that row:

```php
$service = $this->aiServiceSelector->getById($serviceId);
if ($service === null) {
    // The row is gone. Fall back, or tell the admin to pick again.
}
```

`createById()` throws `LocalizedException` in that same situation rather than silently falling
back to another row: another row means another account and another bill, which is not a
substitution to make on the admin's behalf.

## Reaching the platform directly (escape hatch)

`AiClientInterface` covers chat, streaming and single-turn completion. symfony/ai-platform does a
great deal more: executed tool loops via `symfony/ai-agent`, message stores and sessions via
`symfony/ai-chat`, structured output, embeddings, vector stores, image and audio. Mirroring all of
that here would mean re-describing an API that already exists, so instead this module hands over
the platform it already built: credentials resolved, bridge selected, row chosen by the admin.

> **symfony/ai-platform is experimental.** Experimental features are not covered by Symfony's
> [Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html).
> This is not hypothetical on the surface a tool loop touches most: `Message::ofAssistant()` was
> reworked in 0.9 and `Message::ofToolCall()` in 0.11. Code written against `Api\*` is insulated
> from that by the adapter behind it. Code written against symfony/ai types is not, and has to be
> re-verified on every upgrade. Pin the version.

```php
use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\PlatformAwareInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

$client = $this->aiClientFactory->createById($serviceId);

if (!$client instanceof PlatformAwareInterface) {
    return $client->complete($prompt);      // no platform to reach; use the stable surface
}

$result = $client->getPlatform()->invoke(
    $client->getModel(),
    new MessageBag(Message::ofUser($prompt)),
    $client->normalizeOptions(['max_tokens' => 400]),
);
```

Three things to know:

- **The `instanceof` check is the API.** It makes the coupling deliberate and keeps it optional:
  a store that preferences its own client stack does not implement the interface, and your code
  degrades to the stable surface instead of fataling.
- **`getModel()` is the model name to invoke with.** It comes off `AiClientInterface`, and it is
  the model the administrator configured on that row.
- **Keep `normalizeOptions()`.** Calling the platform directly otherwise opts you out of every
  translation described under [Options](#options), including the `max_tokens` Anthropic requires.
  It returns the same array `chat()` would have sent.

### With symfony/ai-agent

The agent component runs the tool loop for you, tools included, which is the main reason to
reach past `AiClientInterface`:

```bash
composer require symfony/ai-agent
```

```php
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;

$toolbox = new Toolbox([$this->myOrderTool]);
$processor = new AgentProcessor($toolbox);

$agent = new Agent($client->getPlatform(), $client->getModel(), [$processor], [$processor]);

$answer = $agent->call('Which orders are still pending?')->getContent();
```

`AgentProcessor` is passed as both input and output processor: it advertises the tools on the way
out and resolves the calls on the way back. It caps itself at 50 tool calls per turn by default
and throws `MaxIterationsExceededException` past that.

Note the trade this makes. This module never executes tools, deliberately: whether a call may run
is policy that belongs to the module owning the tool (a confirmation gate for write actions, a
Magento ACL per tool), and none of it generalises. `Toolbox` **does** execute them. Reaching for
the agent component means taking that policy decision on yourself, which is where it belonged
anyway; just take it knowingly rather than by accident.

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
- `getId()` is the row's stable identity, and the only thing that separates two rows of the
  same provider. It is what the option source stores and what `getById()` resolves.
- An empty array means nothing is configured — expected state on fresh installs; handle it.
- Configuration is read at store scope, in whatever scope is ambient at the moment you call.
  In a storefront request that is the current store, so a per-store setup resolves on its own.
  **In adminhtml, in cron and on the CLI there is no current store**, so the default scope
  answers and a per-website or per-store services list is not reachable from those contexts.
  The selector takes no scope argument: code that needs a specific scope has to establish it
  first (store emulation), the same rule the rest of Magento's configuration follows.

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
