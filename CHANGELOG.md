# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **OpenAI-Compatible provider** (`openai_compatible`), for self-hosted OpenAI-compatible gateways
  and aggregators (LiteLLM, an OpenRouter-style proxy, Eden AI) that speak the Chat Completions wire
  format on a host of the administrator's own choosing. Unlike Ollama and LM Studio it has no
  sensible default host, so the Base URL field ships empty; a trailing `/v1` (or `/v1/`) is stripped
  automatically, since the bridge already appends its own `/v1/chat/completions`. Routed through
  `Symfony\AI\Platform\Bridge\Generic\Factory` (package `symfony/ai-generic-platform`, a soft
  dependency like every non-OpenAI/Anthropic bridge).
- **Typed client exceptions**, including during a stream. `chat()`, `complete()` and `streamChat()`
  now throw one of `Model\Client\AiAuthenticationException`, `AiRateLimitedException` (carrying
  `getRetryAfter(): ?int`), `AiTransientException`, `AiInvalidRequestException` (with the
  `AiContentFilteredException` subtype for a safety-filter refusal) or `AiToolCallException` instead of a single generic `LocalizedException`, so a consumer can retry
  a rate limit, skip a non-retryable rejection, and leave everything else alone without parsing
  messages. Every one still extends the new common base `AiServiceException`, itself a
  `LocalizedException`, so an existing `catch (LocalizedException)` keeps working unchanged. The
  new `Model\Client\AiExceptionMapper` builds them by matching the symfony/ai-platform exception
  class symfony/ai already reports (guarded by `class_exists`, never by parsing HTTP statuses or
  headers itself); an unrecognized failure still comes back as `AiServiceException` rather than
  escaping untyped. `streamChat()`'s `foreach` over the delta stream is now inside the same mapped
  error handling as `invoke()`/`asStream()` — previously a mid-stream failure reached the consumer
  as the raw, unmapped symfony/ai exception, breaking the interface's documented
  `@throws LocalizedException`. A `MaxOutputTokensException` (the Anthropic and OpenResponses
  bridges throw it when a response is truncated at the output token limit) is no longer treated as
  a failure at all: both `chat()` and `streamChat()` now return the assembled turn with
  `FinishReason::Length` and the text collected so far, so a stream can report a truncated answer
  the same way a buffered call already could. See `docs/CONSUMING.md`'s new "Typed exceptions"
  section for the full mapping and which bridges (Ollama, HuggingFace, Azure, OpenRouter, LM
  Studio) report less than the rest.
- **Reasoning is carried between tool-loop turns instead of being dropped.** Providers that think
  before answering (Anthropic with extended thinking, OpenAI/Azure's Responses API, Gemini) return
  a reasoning block alongside the turn and expect it echoed back unchanged on the next request; a
  tool loop that only replayed text and tool calls made the model redo its reasoning every round,
  and dropping a required block from a replayed Anthropic turn could fail the call outright.
  `ChatResponseInterface::getReasoning()` and the new `Api\Data\ReasoningInterface` (`getText()`,
  an opaque `getSignature()` this module never inspects, edits or renders) expose it;
  `ChatMessageInterface::getReasoning()` and `ChatRequestInterface::withAssistantTurn()` carry it
  onto the replayed assistant turn automatically, so an existing tool loop gets this for free.
  `SymfonyAiClient` reads it from `ThinkingResult` parts on a buffered call and from
  `StreamResult::getAssistantMessage()` on a streamed one (the one place a stream's signature,
  sometimes only reported after its block has closed, can be read whole), and rebuilds it as a
  `Thinking` content part — leading the turn's other content, which Anthropic requires — when
  replaying an assistant message. OpenAI and Azure additionally need `include:
  ["reasoning.encrypted_content"]` on the request for the item to come back at all;
  `SymfonyAiClient` now adds it for any service whose bridge declares the `openai_responses`
  dialect, keeping a caller's own `include` values. Providers that report no reasoning return an
  empty list, so nothing changes for them.
- **AI usage tracking**: every call made through `AiClientInterface` is now recorded — token
  counts and metadata only, **never prompt or response content** — and surfaced at
  **Reports > AI Token Usage** as a dashboard (totals, period-over-period change against the same
  elapsed span, a trend chart with a line per consumer or per service row, and breakdowns by
  consumer and by service), an admin grid of individual calls, and a
  `bin/magento mageos:ai:usage` CLI report. See `docs/USAGE-TRACKING.md` for the merchant-facing
  guide and `docs/ARCHITECTURE.md`'s "Recording path"/"Roll-up path" sections and its usage-scope
  decision record for why content, cost estimation and the `getPlatform()` escape hatch are
  deliberately out of scope.
- `AiClientInterface::OPTION_CONSUMER` and `getConsumer()`: a **consumer** identifies which module
  or feature a call is attributed to, so usage recorded through one shared client can still be told
  apart per feature. `AiClientFactoryInterface::create()` and `createById()` both gained an
  optional trailing `?string $consumer = null` to set it once for every call a built client makes;
  the option overrides it for a single call. Both default to
  `Api\Data\UsageRecordInterface::CONSUMER_UNKNOWN` when neither is set. See
  `docs/CONSUMING.md`'s "Naming your module as a consumer".
- `Model\Client\RecordingAiClient` and `Model\Client\RecordingPlatformAwareAiClient`: decorators
  `ClientFactory` wraps every built client in (unless usage tracking is switched off), writing one
  usage row per completed call through the new repositories below. The `PlatformAwareInterface`
  variant exists so the decorator never falsely claims a wrapped client that deliberately does not
  implement that interface now does. `complete()` is reimplemented rather than delegated on both,
  since a delegate's own `complete()` calls its own internal `chat()`, not the decorator's, and
  delegating would record nothing for it. A save failure is logged and swallowed, never thrown: it
  runs after the wrapped call already succeeded, and a storage problem must not fail a call that
  otherwise worked.
- Two new database tables (`etc/db_schema.xml`): `mageos_ai_usage_log`, one row per call the
  client actually sent, whether it succeeded, failed after reaching the provider, or succeeded
  reporting no usage at all (service id/code, model, consumer, store id, six independently-nullable
  token counts: prompt, completion, total, cache read, cache write, reasoning, plus whether it
  streamed, whether it failed, `created_at`), and `mageos_ai_usage_daily`, one row per (day, service id,
  model, consumer, store id) grouping key plus a `failed_calls` count, built by a scheduled
  roll-up before the raw rows behind it are pruned. Both table comments state outright that they
  hold counts and metadata only, never call content.
  `Api\UsageRecordRepositoryInterface` and `Api\UsageDailyRepositoryInterface` are the persistence
  contracts; every SQL statement lives in the two resource models behind them
  (`Model\ResourceModel\Usage\UsageLog`/`UsageDaily`), which is what keeps the repositories
  unit-testable against a small fake instead of Magento's full DB adapter.
- `Model\Usage\UsageMaintenance`, run by the new `Cron\RollUpUsage` job
  (`etc/crontab.xml`, schedule at `mageos_ai/usage/cron_expr`): rolls up every whole local day of
  the raw log older than the configured retention into the daily table, deletes exactly the raw
  rows it rolled up, then prunes daily rows past their own (longer) retention — in that order,
  non-negotiably, since reversing it would mean silent data loss. Day boundaries are computed in
  PHP from the store's configured timezone with plain `\DateTimeImmutable`/`\DateTimeZone`
  arithmetic, never SQL's `DATE()` or `CONVERT_TZ()`, so a customer install with no MySQL timezone
  tables loaded still gets a correct boundary and a daylight-saving transition still produces
  exactly one bucket.
- Four new config fields under **Stores > Configuration > Mage-OS > AI Configuration > Usage
  Tracking** (`mageos_ai/usage/*`): `enabled` (default on), `retention_days` (default 30, how long
  individual calls are kept before roll-up), `daily_retention_days` (default 730, how long the
  daily aggregates outlive them), and `cron_expr` (default `0 3 * * *`), which `crontab.xml` reads
  directly through a `<config_path>` rather than through this module's own config reader, so the
  cleanup job always has a schedule even before an administrator ever opens the group.
- `Api\UsageStatsInterface` (impl. `Model\Usage\UsageStats`): the one read contract the dashboard,
  its graphs, the CLI report and any other module go through to answer "how much AI usage
  happened, by whom, through which service, over time". A requested `Api\Data\Period` can straddle
  the boundary between the raw log and the daily roll-up; the implementation queries whichever
  table or tables the window touches and merges the result without ever double-counting a day
  present in both. `Api\Data\Period` is an immutable `[start, end)` UTC window with named
  constructors (`today()`, `thisMonth()`, `thisYear()`) that resolve local calendar boundaries in
  the store timezone before converting once to UTC; `Api\Data\Granularity` (`Day`/`Month`) drives
  `getTimeSeries()`, which always returns one bucket per calendar unit in the period, including
  a bucket with no recorded usage, so a caller feeding it straight into a graph never fills a gap
  itself.
- `bin/magento mageos:ai:usage`: prints usage totals and a per-consumer breakdown for `--period`
  (`today`/`month`/`year`, default `month`), optionally narrowed with `--consumer`, in a `--format`
  of `table` (default, for a human) or `json` (stable keys, for a script). Reads through
  `UsageStatsInterface`, the same contract the dashboard uses, so the CLI and the dashboard can
  never disagree about a period's totals.
- A new admin page at **Reports > AI Token Usage**, under a **Mage-OS AI** heading in the Reports
  menu (`Controller\Adminhtml\Usage\Index`, ACL resources `MageOS_AiBase::reports` for the group
  and `MageOS_AiBase::usage` for the page, both under `Magento_Reports::report`):
  `Block\Adminhtml\Usage\Dashboard` renders the totals, the trend and the breakdowns as inline SVG
  through `Model\Usage\Graph\SvgRenderer` — no JS charting dependency, and no script at all: the
  trend's crosshair and value panel are revealed by CSS on the hovered column, so the page works
  under a strict CSP. A read-only `Magento_Ui` grid below it lists individual raw-log rows,
  filterable by date, consumer, service and the token counts, with a notice naming the *configured*
  retention windows and explaining that older usage lives on as daily aggregates.
- The trend chart draws up to five series with the remainder folded into **Other**, switched
  between consumers and service rows by a selector that, like the period selector, carries its
  state in the URL. Series colours are the Mage-OS admin hues in fixed slot order, validated as a
  set against the chart surface for colour-vision separation and contrast; a legend is always
  present, so identity is never carried by colour alone.
- `UsageStatsInterface::getTimeSeriesByConsumer()` and `getTimeSeriesByService()`, returning one
  dense ordered series per group over the same raw/daily union the totals use, and
  `seriesRangeGrouped()` on both repositories behind them. Query counts are unchanged on either
  side: the daily table adds a second `GROUP BY` to its single statement, and the raw table groups
  inside the per-bucket statement it already issued.


- **Services can be turned off without being deleted.** Each configured row has an enable toggle; a disabled row keeps its id and its credentials, stays editable in the admin form, and disappears from `AiServiceSelectorInterface` entirely, so nothing calls it: the option source stops offering it, `AiClientFactoryInterface::create()` skips it, `createById()` refuses it, and a module reading credentials to call a provider itself never sees it. Filtering happens in `Model\AiServiceSelector` rather than at each call site, which is what makes "disabled" mean the same thing everywhere. Rows saved before this setting existed carry no value for it and count as enabled, so upgrading cannot silently stop a working integration. Read it through the new `Api\Data\AiServiceInterface::isEnabled()`.
- **A configured row can be named for what it is for.** The same backend is often configured more than once on different keys ("Chat AI" and "Summaries" on two Anthropic accounts), and the purpose is what an administrator recognises when another module asks them to pick a service. The name is optional, hidden behind a pencil in the row heading until asked for, and read through the new `Api\Data\AiServiceInterface::getLabel()`. `Model\Config\Source\ConfiguredService` lists a named row under its name with the provider behind it (`Chat AI (OpenAI, gpt-4o)`), because a row called Chat AI still has to say which backend it bills.
- **A Mage-OS configuration tab**, carrying the icon the Mage-OS admin theme already ships. Mage-OS declares no tab of its own, and the modules around this one had each landed somewhere different: the catalog tab, an existing section, or a tab a single module invented. This is the base AI module, so it declares the shared one and moves **AI Configuration** into it, out of Services.
- **An end-to-end suite** (`tests/End-2-End`, Playwright) driving the real admin form, and a CI workflow that runs it against a booted store in both developer and production mode, since the form offers different providers in each. It covers what only a browser can: which row a button acts on, the confirmation before a delete, focus after adding a provider, the copy button, and the enable toggle actually reaching the server. **It deletes every configured service on the target install**, so it refuses to run without `E2E_DISPOSABLE_ENVIRONMENT=1`.
- `Api\ChatRequestBuilderInterface` (+ `Model\Chat\ChatRequestBuilder`), so a consumer can assemble a request without naming a `Model\*` class — which the consumer guide told people to avoid while every example did it. Inject the generated `ChatRequestBuilderInterfaceFactory` and chain `withSystemMessage()`, `withUserMessage()`, `withAssistantTurn()`, `withToolResult()`, `withTool()`, `build()`. Each method returns a new builder, so a builder holding a common preamble can be branched from. Every `Api\Data` chat contract also gained a `<preference>` in `di.xml` (`ChatRequestInterface`, `ChatMessageInterface`, `ChatResponseInterface`, `ToolDefinitionInterface`, `ToolCallInterface`, `TokenUsageInterface`, `StreamChunkInterface`), so their auto-generated `*InterfaceFactory` classes resolve for consumers who would rather build one directly. Without those preferences, following the documented rule was impossible.
- `streamChat()` now *returns* a `ChatResponseInterface` from its generator: text concatenated, tool calls collected, final token counts and stop reason attached. A streaming tool loop needs that assembled turn to append before the next iteration, and every consumer was left to accumulate it by hand. Read it with `$stream->getReturn()` after the loop; breaking out early raises from PHP, which is the right signal that there is no complete turn.
- `ChatRequestInterface::withAssistantTurn(ChatResponseInterface)`: appends the model's own turn with its tool calls attached. Appending the text and forgetting the calls makes the provider reject the tool results that follow, and it was the shape the tool-loop example asked people to write by hand.
- `AiClientInterface::getServiceId()` and `getModel()`. Token and cost accounting is per row and per model — the code alone identifies neither, since the same backend can be configured twice with different billing owners — and the factory already resolved both when it built the client, so re-resolving them through the selector was duplicated work.
- `Model\Client\OptionNormalizer`: the four options every provider has (`max_tokens`, `temperature`, `top_p`, `stop`) are now translated into the target provider's own names instead of being passed through raw. Bridges merge options into the provider's request body nearly untouched and OpenAI-compatible endpoints reject unknown body fields with a 400, so the same `max_tokens` is `max_output_tokens` on OpenAI's and Azure's Responses API, `maxOutputTokens` on Google, `num_predict` on Ollama — and Anthropic rejects a request that omits it entirely, which the client now supplies (4096) when the caller does not. Moving a configured row between providers no longer fails, or silently generates under a different cap, from configuration that did not change. An option a provider has no equivalent for (`stop` on the Responses API) raises a `LocalizedException` naming both rather than being dropped. Everything else still passes through verbatim, so provider-specific features stay reachable. An option the caller spelled the provider's own way wins over the neutral one, matching how a required default already defers to it. Numeric strings are sent as numbers, because both `di.xml` (Magento's `number` argument interpreter returns the raw node value) and `core_config_data` yield strings, and Anthropic rejects `"max_tokens": "4096"` as not-an-integer. Which shape a provider speaks is the `dialect` on its `BridgeRegistry` entry; the dialects are `di.xml` data, so a third party declares one alongside its bridge.
- `tool_choice` and `reasoning_effort` are provider-neutral options on `Model\Client\OptionNormalizer`, next to `max_tokens` and friends. Both need their *value* translated, not only their name (Anthropic's `required` is `any`), and one value can expand into several request fields (Anthropic's `reasoning_effort` sets `thinking` and `output_config`), so dialects gained a `values` key: canonical value => request fragment to merge in, with `{{name}}` replaced by the tool name for `['tool' => '<name>']`. Third parties declaring a dialect fill the same table. A canonical value a provider cannot express throws, except `tool_choice: auto` on a dialect with no tool choice at all (Ollama), which is a no-op. A value outside the canonical set is the provider's own and passes through untouched, so a caller already forcing a tool in Anthropic's native shape is unaffected. A few translations only work on some models of a provider; `docs/PROVIDERS.md` lists them.
- `Model\ServiceRegistry`: the registered backends in one `di.xml` list, keyed by `getCode()`. The same nine-item array was repeated four times with a comment asking maintainers to keep it in sync, and a third party registering a provider had to remember all four spots; a provider missing from one of them degrades silently (a raw machine code in the admin dropdown, or credentials falling back to a field-name heuristic instead of the provider's declared schema) rather than failing.
- `Api\PlatformAwareInterface`: an opt-in escape hatch handing over the symfony/ai-platform instance behind a client, credentials resolved and bridge selected, so a consumer can reach the parts of Symfony AI this module does not mirror (executed tool loops via `symfony/ai-agent`, message stores via `symfony/ai-chat`, structured output, embeddings, vector stores, image and audio) instead of re-deriving a platform from raw configuration. Deliberately a separate interface reached by `instanceof` rather than a method on `AiClientInterface`, so the coupling is visible at the call site and a store preferencing its own client stack can decline it; it returns `object` rather than `PlatformInterface` so the soft dependency survives on installs without the package. It also carries `normalizeOptions()`, because calling the platform directly otherwise opts out of the provider option translation, which is the one part of the client layer worth keeping when the rest is bypassed. **Everything past `getPlatform()` sits outside this module's compatibility promise:** symfony/ai-platform is experimental and not covered by Symfony's Backward Compatibility Promise, which is not hypothetical on that surface (`Message::ofAssistant()` was reworked in 0.9, `Message::ofToolCall()` in 0.11). README.md and `docs/CONSUMING.md` state this where a reader meets it.
- `Api\Data\FinishReason`: why the model stopped, normalized across providers.
- Unit tests for the request builder, the option normalizer (including the per-provider option names, verified against the bridge packages), the service registry, the assembled streaming turn, the finish reason and the selector's parse-once behaviour. The `di.xml` wiring test now asserts that the provider list exists in exactly one place, that item names agree with the providers' own codes, and that every bridge declares a dialect the normalizer knows.
- Multi-turn chat, tool calling, streaming and token accounting on `AiClientInterface`, which until now was single-turn text only (`complete(string): string`). `chat(ChatRequestInterface, array $options): ChatResponseInterface` sends a conversation and returns text, requested tool calls, token counts and the provider's stop reason; `streamChat()` returns a `\Generator` of `StreamChunkInterface` (text deltas, reasoning deltas, completed tool calls, token counts). `complete()` is kept as a convenience and now runs through `chat()`, so there is one code path. New contracts in `Api\Data`: `ChatRequestInterface`, `ChatResponseInterface`, `ChatMessageInterface`, `ToolDefinitionInterface`, `ToolCallInterface`, `TokenUsageInterface`, `StreamChunkInterface`, plus the `MessageRole` and `StreamChunkType` enums, with value objects in `Model\Chat`.
- Tool calls are surfaced, never executed. The request carries tool definitions, the response carries the calls the model asked for, and `ChatRequestInterface::withToolResult()` feeds each result back for the next turn. Whether a call may run is policy belonging to the module that owns the tool (confirmation for write actions, per-tool ACL, instruction injection), and none of it generalises into this module.
- Streamed tool calls arrive whole, with their arguments already accumulated and JSON-decoded by the provider bridge, so consumers no longer hand-parse SSE frames or stitch `input_json_delta` fragments. A turn requesting several tools yields one chunk per call, so `getToolCall()` always means exactly one; the provider signals them in a single delta, and reporting only the first would leave every tool but one unrun and unanswered. Reasoning ("thinking") deltas are surfaced as their own chunk type rather than mixed into the answer text.
- Reusable option source so consumer modules can let an administrator pick a configured AI service from their own `system.xml`: `Model\Config\Source\ConfiguredService` lists one option per configured row, labelled by provider display name and configured model (`OpenAI (gpt-4o)`), and `Model\Config\Source\ConfiguredServiceWithAutomatic` prepends an empty-valued "Automatic (first usable service)" option. Rows whose bridge is missing stay selectable but are labelled (`Ollama (llama3, bridge not installed)`), since modules calling a provider with their own HTTP client need no bridge; rows of an unregistered provider fall back to their raw code rather than disappearing. Rows that would share a label are numbered.
- `AiServiceInterface::getId()`: the stored row key, stable for the life of the row, exposed so a configured service has an identity that survives into another module's configuration. The service code cannot serve as one, because the same backend can be registered more than once.
- `AiServiceSelectorInterface::getById(string $id): ?AiServiceInterface` and `AiClientFactoryInterface::createById(string $serviceId): AiClientInterface`, resolving a stored selection back to a configuration row or a ready-to-use client. Unlike `create('openai')`, `createById()` reaches rows that are not the first of their service code. A stale id (the admin deleted the row) returns `null` from the selector and throws from the client factory rather than falling back to another row, which would mean another account and another bill.
- Unit tests for the option source and the id-addressed lookups; a `di.xml` wiring test asserting the option source knows every provider the admin form offers.
- PHPStan static analysis at level 5, wired into CI as a `static-analysis` job on PHP 8.2 and 8.4. The reusable Magento workflow has no static-analysis step, so this runs the module standalone: phpstan needs the framework classes on the autoloader, not a working install. `bitexpert/phpstan-magento` is what makes analysis of a Magento module viable at all, since without it every reference to an auto-generated `*Factory` is an unknown class (58 errors at level 0 alone). Array shapes are documented throughout so the level can be raised later without re-deriving them.
- The admin form now shows which providers can actually be used through the bundled client. A provider whose Symfony AI bridge package is not installed is greyed out and labelled, with a ready-to-paste `composer require` command listing every missing package; a provider with no bridge released upstream at all (possible for third-party providers) is labelled separately, since there is nothing to install. Greyed-out providers stay configurable, because modules that call the provider with their own HTTP client still read that configuration.

- Per-service "Refresh Models" button in the admin form (saved rows only): POSTs to a new `mageos_ai/service/refreshmodels` adminhtml route (`Controller\Adminhtml\Service\RefreshModels`, reusing the `MageOS_AiBase::configuration` ACL resource) that live-fetches the provider's model list with the saved (decrypted) credentials, persists it at `mageos_ai/services/models/<code>` (default scope, with a fetched-at timestamp) and updates the row's model select in place. Refresh is strictly manual — no automatic or periodic fetching. Providers opt in via the new `Api\ModelListProviderInterface` (`fetchModels(array $configuration): array`); OpenAI, Anthropic, OpenRouter, Ollama and LM Studio implement it. The stored list feeds the form through `Model\ModelList\Resolver`, falling back to each service's curated `getSupportedModels()` when nothing was fetched yet; the service classes themselves stay storage-free.
- Per-service "Test Connection" button in the admin form (saved rows only): POSTs to a new `mageos_ai/service/test` adminhtml route (`Controller\Adminhtml\Service\Test`, reusing the `MageOS_AiBase::configuration` ACL resource) which sends a minimal prompt through `AiClientFactoryInterface` and reports latency and a response snippet (or the error) inline. Requires `symfony/ai-platform`; when several rows share a service code, the first configured row of that code is tested.
- `FieldDescriptorInterface::isEncrypted()`: per-field opt-in flag marking a field as a credential that is encrypted at rest and masked in the admin form (`FieldDescriptor` takes an `encrypted` constructor argument, default `false`). `FieldFactoryTrait::apiKeyField()` sets it, so all bundled providers' `api_key` fields are flagged. The admin form forces `type="password"` inputs for encrypted fields regardless of their declared type.
- `AiClientInterface` / `AiClientFactoryInterface`: provider-agnostic client layer backed by symfony/ai-platform bridges (soft dependency; bridge `Factory` FQCNs mapped per service code in `di.xml`, guarded by `class_exists`/`method_exists`; verified against symfony/ai-platform v0.11.0). Third-party modules can register additional bridges or replace the implementation via `<preference>`.
- Credential fields (`apikey`, `api_key`, `token`, `secret`) are now encrypted at rest via `EncryptedServices` config backend + `SensitiveDataProcessor`. Plaintext rows saved before this change are detected and keep working; they are re-encrypted on the next admin save.
- Azure service: `endpoint` configuration field (required by the Azure OpenAI bridge).
- Unit tests for `SensitiveDataProcessor` and `ClientFactory`.
- Unit tests for the `EncryptedServices` placeholder round-trip and `SensitiveDataProcessor` masking/restore.

### Changed
- **BREAKING:** `Api\Data\ChatResponseInterface` and `Api\Data\ChatMessageInterface` each gained
  `getReasoning(): array` (a list of the new `Api\Data\ReasoningInterface`), which carries a
  model's reasoning between tool-loop turns. Custom implementations of either must add it; the
  bundled `Model\Chat\ChatResponse` and `Model\Chat\ChatMessage` already do, returning an empty
  list when there is none.
- **BREAKING:** `Model\Client\SymfonyAiClient::__construct()` takes a new required
  `Model\Client\BridgeRegistry $bridgeRegistry` argument, directly after `AiExceptionMapper
  $exceptionMapper` and before the trailing `?string $consumer`. It decides which services need
  the Responses API's reasoning `include`. The ObjectManager resolves it for every client built
  through `ClientFactory`; code constructing `SymfonyAiClient` directly must pass one.
- **BREAKING:** `Api\Data\StreamChunkType` gained `ThinkingStart` and `ToolCallStart`. A bridge
  that reports the platform's `ThinkingStart`, `ToolCallStart` or `ToolInputDelta` delta — the
  Anthropic bridge does, for both signals, as soon as the model opens a thinking or tool-use block
  — now surfaces it as a chunk of the matching new type, instead of `SymfonyAiClient` silently
  dropping it. `ThinkingStart` carries no payload; `ToolCallStart` carries the call's id and name
  with empty arguments, and `ToolInputDelta` maps to the same chunk type rather than exposing the
  partial JSON a consumer would otherwise have to reassemble itself. The completed call still
  arrives exactly once as before, arguments included, on its own `ToolCall` chunk, so a tool loop
  needs no change. A `match` over `StreamChunkType` with no default arm throws
  `UnhandledMatchError` the moment either new case is yielded; see docs/CONSUMING.md's
  "Streaming" section for the updated example and why a default arm is worth adding. A bridge that
  reports neither signal is unaffected: the consumer simply never sees that chunk kind.
- **BREAKING:** `Api\AiClientInterface` gained `getConsumer()`, and `Api\Data\TokenUsageInterface` gained `getCacheReadTokens()`, `getCacheWriteTokens()` and `getReasoningTokens()`. Cache is reported as two separate, independently-nullable subsets of the prompt count, a read and a write, rather than one combined figure, since providers typically bill the two at different rates; every bundled provider's prompt count is also normalized to include cache the same way, even the one (Anthropic) whose own API excludes it, so a caller never has to know which provider answered to read the prompt count honestly. Custom implementations of `TokenUsageInterface` must add all three new methods. The bundled `Model\Client\SymfonyAiClient` and `Model\Chat\TokenUsage` already do; see `docs/ARCHITECTURE.md`'s "Client path" for the normalization rule and `docs/USAGE-TRACKING.md`'s "Token counts" for what it means for a recorded row.
- **BREAKING:** `Api\Data\UsageRecordInterface` gained `getCacheWriteTokens()` and `isFailed()`, and `getInputTokens()`, `getOutputTokens()` and `getTotalTokens()` are now nullable (`?int`). A row is now written for a call that failed before the provider reported any usage, or that succeeded reporting none at all, and `null` on any of these means "the provider never reported this count", never "reported as zero". Custom implementations must add the two new methods and widen the three existing ones. The bundled `Model\Usage\UsageRecord` already does.
- **BREAKING:** `Api\Data\UsageTotalsInterface` gained `getCacheWriteTokens()` and `getFailedCalls()`, alongside the already-listed `getCacheReadTokens()` and `getReasoningTokens()`. `getFailedCalls()` is counted separately from, not subtracted out of, `getCalls()`: a failed call still used a connection and may still have spent tokens before it threw. Custom implementations must add both. The bundled `Model\Usage\UsageTotals` already does.
- **BREAKING:** `Model\Client\SymfonyAiClient::__construct()` takes a new required `Model\Client\UsageNormalizer $usageNormalizer` argument, directly after `OptionNormalizer $optionNormalizer` and before the trailing `?string $consumer`. It is what applies the cache-folding rule above per bridge; code constructing `SymfonyAiClient` directly must pass one, though the ObjectManager resolves it automatically for every client built through `ClientFactory`.
- A failing `streamChat()` now yields one final `StreamChunkType::Usage` chunk, built from whatever the platform had already billed, before it rethrows the original exception unchanged. A caller whose loop stops on that chunk, or exits early for any other reason, never reaches the iteration that raises the exception; see `docs/CONSUMING.md`'s "A failing stream still reports usage".
- `Model\Client\AiRequestNotSentException` (extends `LocalizedException`): thrown for a call rejected before it ever reached the provider, an unsupported option, an invalid model override, a tool result message missing its call id. `RecordingAiClient` rethrows it unchanged without writing a usage row, since nothing was ever billed for it; every other exception from a call that did reach the provider is still recorded with the `failed` flag set. See `docs/CONSUMING.md`'s "Failure modes to handle" and `docs/USAGE-TRACKING.md`'s "Failed calls and null-token rows".
- **BREAKING:** `Api\AiClientFactoryInterface::create()` and `createById()` each gained a trailing `?string $consumer = null` parameter. The argument is optional for *callers*, so no call site has to change, but an implementation of the interface must widen its own signature to match or PHP will refuse to load it.
- **symfony/ai bumped to `^0.14`** for `symfony/ai-platform` and the two required bridges, and the suggested bridges follow the same line. None of the BC breaks in 0.13 or 0.14 land on a surface this module implements: 0.13's `Result\Stream\ListenerInterface::onError()` and 0.14's `TokenUsage\TokenUsageInterface::getModel()` are both interfaces the adapter only reads from, `DeferredResult`'s typed readers moved to a trait without changing their signatures, and 0.14's MiniMax/Replicate job rework concerns bridges the module does not register. Bridge `createPlatform()` signatures are unchanged and now verified against v0.14.0. Streaming bridges emit a new `ToolCallStart` delta, which `SymfonyAiClient` ignores the way it ignores every delta carrying no payload of its own; completed calls still arrive as one `ToolCallComplete`.
- **BREAKING:** `Api\Data\AiServiceInterface` gained `isEnabled()` and `getLabel()`. Custom implementations must add them; `Model\AiService` reads both from the stored row, so nothing else has to.
- **The admin form's provider list depends on the application mode.** In production only providers that can actually be used are offered, with one sentence pointing at a developer for the rest; developer mode keeps every provider and the instructions for installing a bridge, because that is where someone can act on them. Only the buttons are filtered: the field schema still carries every registered provider, so a row saved for one of them keeps rendering with its fields and its credentials.
- **Row actions moved into the row heading** and the Action column is gone, which gives the fields the width the column was holding. Refreshing the model list became an icon on the model field it refills rather than a second full-width button, and Test Connection is a control the size of the others instead of the loudest thing in the row.
- The wiring tests are integration tests now, and read the running application instead of the files it is configured by. `Test/Unit/Etc/*` parsed `di.xml` and `system.xml` with XPath, which can only ever prove that a file says what it says: it stays green when a `<type>` name no longer matches its class, when another module overrides the argument, and when the module is not enabled at all. In their place, `Test/Integration` resolves the registries, the option normalizer, the option source and the config backend through the ObjectManager and asserts what they do — including the credential round trip through a real encryptor and `core_config_data`, and the masking the admin form actually receives. `Test/Unit/Etc/BridgeWiringTest`, `Test/Unit/Etc/SensitiveConfigPathsTest`, `Test/Unit/Etc/ConfiguredServiceWiringTest` and `Test/Unit/Model/Config/Backend/EncryptedServicesTest` are gone; what they asserted is covered by behaviour now, except for one orphan check (a bridge registered against a code no provider uses), which is dead configuration rather than a failure. `.github/check-extension.json` keeps the reusable workflow's `integration_test` job switched on explicitly.
- PHPStan runs at level 10, the highest the 2.x line defines, up from 5. Nothing was silenced to get there and no baseline was added: the docblocks now carry the array shapes the code always relied on (`array<string,mixed>` request options, the `di.xml` dialect shape, `list<>` where an interface already promised a list), and the interop with symfony/ai-platform names the real platform types in its annotations while its native signatures stay `object`, so the soft dependency is unchanged and those calls are checked for the first time. The one remaining `@phpstan-ignore` is the tool JSON Schema handed to Symfony's `Tool`, which is written by the consumer at runtime and only the provider can rule on.
- `Model\Config\SensitiveDataProcessor` and `Model\Config\Backend\EncryptedServices` annotate stored configuration rows as `array<array-key,mixed>` rather than `array<string,mixed>`. Rows are decoded from JSON and PHP turns a numeric object key into an int, which is why the processor was already casting keys to strings.

- **BREAKING:** `ChatResponseInterface::getFinishReason()` now returns the new `Api\Data\FinishReason` enum instead of `?string`, and `getRawFinishReason(): ?string` was added for the provider's own wording. Both were always `null` before: `SymfonyAiClient` never passed the value, so a consumer writing "did we hit the token limit?" against the documented contract got a silently always-false branch. It is populated from the platform's result metadata now. The normalized enum rather than the raw string because the same event is `length` at OpenAI, `max_tokens` at Anthropic and `MAX_TOKENS` at Google, and a branch that has to know all three is not what a provider-agnostic client is for.
- **BREAKING:** `Api\AiClientInterface` gained `getServiceId()` and `getModel()`; `Api\Data\ChatRequestInterface` gained `withAssistantTurn()`. Custom implementations must add them.
- **BREAKING:** `Model\Client\SymfonyAiClient::__construct()` takes `$serviceId` and an `OptionNormalizer` after `$serviceCode`.
- **BREAKING:** the `services` array argument moved off `Block\Adminhtml\Configuration\Services`, `Model\Config\SensitiveDataProcessor`, `Model\Config\Source\ConfiguredService` and `Controller\Adminhtml\Service\RefreshModels` onto `Model\ServiceRegistry`, which those four now take as a constructor argument. Third parties registering a provider move their one `di.xml` entry there, and drop the other three.
- **BREAKING:** `Model\Client\BridgeRegistry` entries gained a `dialect` key naming the provider's request-option shape. A bridge without one has its caller's options passed through untouched, which is the mis-cap the normalizer exists to prevent.
- Streamed token counts are reported at all. The platform lifts usage out of the delta sequence into the result metadata, so the client's usage-delta branch never fired against a real bridge and `StreamChunkType::Usage` was effectively dead: no streamed call reported any usage. It is now read from the metadata once the stream ends and yielded as a final usage chunk.
- `Model\AiServiceSelector` parses the stored configuration once per distinct stored value instead of on every `getAll()`/`getByCode()`/`getById()`. Parsing decrypts every credential of every configured row, which a tool loop resolving its service per iteration paid for on every turn. The memo is keyed on the raw value rather than simply held, so store emulation (cron, transactional email) cannot be served another scope's credentials.
- `AiServiceSelectorInterface` now documents its scope behaviour: store scope, ambient scope, no scope argument — so adminhtml, cron and the CLI always read the default scope and a per-website services list is not reachable from them. Previously true but undocumented, and something you found out by hitting it.
- **BREAKING:** `Api\AiClientInterface` gained `chat()` and `streamChat()`. Custom implementations must add both.

- **BREAKING:** `Api\Data\AiServiceInterface` gained `getId()` and `Api\AiServiceSelectorInterface` gained `getById()`; `Api\AiClientFactoryInterface` gained `createById()`. Custom implementations of any of the three must add the new method. `Model\AiService` now takes the row id as its first constructor argument, so `AiServiceInterfaceFactory::create()` calls must pass an `id` key.

- **BREAKING:** `AiClientFactoryInterface::create()` called without a service code now returns the first configured service **whose bridge is installed**, rather than simply the first configured service. Admin row order is unrelated to which bridge packages an install has, so the old rule let one unusable provider at the top of the list disable the no-argument entry point for every consumer, even with a usable provider configured below it. When nothing is usable, the error names each configured service and the package it needs. Calling `create('somecode')` explicitly is unchanged and still fails when that provider's bridge is missing, rather than silently resolving to a different provider and billing the wrong account.

- **BREAKING:** the `platformFactories` array argument on `Model\Client\ClientFactory` is replaced by a `Model\Client\BridgeRegistry` collaborator, wired from a single `bridges` argument in `di.xml` that carries both the bridge factory FQCN and the composer package providing it. Third parties registering their own bridge should move their `di.xml` entry to `BridgeRegistry`.

- **BREAKING:** `FieldDescriptorInterface` gained `isEncrypted(): bool`; custom implementations must add it.
- **BREAKING:** `SensitiveDataProcessor` row methods now require the service code as their first argument (`encryptRow`, `decryptRow`, `maskRow`, `restoreRow`), and the class accepts a `services` array (`AiServiceConfigurationInterface[]`, wired in `di.xml`). Sensitivity is decided by the provider field schema (`isEncrypted()`); for unknown service codes or fields not in the schema, the previous field-name heuristic (`apikey`, `api_key`, `token`, `secret`) remains as a fallback — third-party rows may outlive their provider module, and it adds defense in depth.
- **BREAKING:** credential field renamed from `apikey` to `api_key` (form schema, stored config, `ClientFactory` reads). `SensitiveDataProcessor` still treats the legacy `apikey` spelling as sensitive for third-party providers.
- Stored credentials are no longer decrypted into the admin form; they are shown as an obscured `******` placeholder. Saving an unchanged placeholder keeps the previously stored (encrypted) value; typed values replace it. Existing form rows now keep their stored row IDs so placeholders map back to the right row.
- `services.phtml` no longer uses an inline `<script>` block; the script is emitted via `SecureHtmlRenderer::renderTag()` for CSP compliance.
- `AiServiceSelector` reads configuration with store scope (`ScopeInterface::SCOPE_STORE`), enabling per-store service configuration.
- `composer.json`: declare `magento/module-backend`, `module-config`, `module-store` requirements; suggest one bridge package per provider (`symfony/ai-open-ai-platform`, `symfony/ai-anthropic-platform`, and so on) rather than `symfony/ai-platform`, which has shipped no bridges since 0.12; exclude `registration.php` from the classmap.

### Fixed
- `ChatRequestBuilder::withAssistantTurn()` now keeps the response's reasoning on the replayed assistant turn, as `ChatRequest::withAssistantTurn()` already did. It only passed the text and tool calls on, so a tool loop built with the builder silently dropped the reasoning a thinking provider expects back on the next request, and on Anthropic with extended thinking that request could be rejected.
- Turning a saved service off did nothing. The enable toggle is a checkbox with a hidden input of the same name carrying the "off" value, and the form restores stored values by name onto the first matching element, which is the hidden one: the stored "on" overwrote the "off" before the form was ever submitted. Found by the end-to-end suite on its first run.
- **Test Connection and Refresh Models now act on the row their button sits in.** Both sent only the service code, and both controllers resolved that through `create($code)`, which returns the *first* configured row of that code. An administrator with two rows of the same provider, which is the setup row ids exist for, tested the first row's credentials from the second row's button and read the result against the key in front of them; Refresh Models fetched the model list with that same wrong account. The form now sends the row id and the controllers resolve it through `createById()` and `getById()`. A request carrying only a code still resolves as before.
- A configured row now says which provider it is. The row rendered its fields and nothing else, so two rows of the same backend were identical down to the pixel, and telling one from the other meant reading the model out of a `select`. Each row now carries the provider's display name with its configured model beside it, updating as the model changes.
- Test and refresh results render under the row's fields instead of inside the 260px action column, where a provider's error wrapped into five lines.
- Model-list failures say what went wrong before they say what the status was: a 401 or 403 reports the API key as rejected, a 404 points at the base URL, a 429 says the account is being rate limited. Every message still carries the status.
- The `composer require` hint wraps instead of running past the edge of its box, and has a Copy button. It is there to be pasted into a shell, and an overlay scrollbar gave no sign that most of the command was off-screen.
- Removing a service asks first. The row disappeared on a single click, and because credentials are masked an administrator cannot retype what a mis-click destroyed.
- The configurator no longer squeezes Magento's own field label until it renders one letter per line, which is what the config form's auto table layout did to it below roughly 1200px.
- Every field is now a real `<label for>` with an id, and credential inputs carry `autocomplete="off"` so password managers stop offering to save a provider's API key as the admin's own password.
- Adding a provider moves focus into the new row's first field, an empty configuration says so instead of rendering an empty table, and the field's comment no longer points at an "Add Service button" that does not exist.
- `Model\Client\ClientFactory` now refuses a configured row with no model selected, and a bridge factory that returns no platform, instead of passing either on. An empty model reached the provider as a request for a model that does not exist, and came back as the provider's own words about it, naming neither this module nor the row an administrator has to go fix.
- `Model\ModelList\Storage::getModels()` validates the stored list on the way out. It is read back from `core_config_data`, where a hand-edited row can hold anything, and the admin form renders those entries straight into option labels.
- The three tests still declaring their data provider in a docblock now use the `#[DataProvider]` attribute like the rest of the suite. PHPUnit 11 dropped docblock metadata, so under the version a recent Magento install brings they ran with no arguments and failed with an `ArgumentCountError` instead of testing anything.
- `LocalizedException` was given a `\Throwable` as its `$cause`, which its constructor does not accept (`Exception|null`). A provider bridge or HTTP client throwing an `\Error` rather than an `\Exception` would have raised a `TypeError` while reporting the original failure, losing the real cause. Affected `Model\Client\SymfonyAiClient` and `Model\ModelList\HttpFetcher`; the cause is now attached only when it is an `\Exception`, and the message still carries the original text either way.
- `Block\Adminhtml\Configuration\Services::getServicesButtons()` documented a return shape of `{code, name}` while actually returning five keys including `available` and `supported`, which the annotation had been wrong about since provider availability was added. Its `$_addButtonLabel` assignment also passed a `Phrase` where Magento declares a `string`.
- Registered backend definitions coming from `di.xml` are now validated once at the boundary in the admin form block rather than trusted by annotation; DI array arguments are not type-checked by the framework.
- "Refresh Models" is no longer a silent no-op for providers whose model field is free text (OpenRouter, Ollama, LM Studio). The refreshed list was fetched, persisted and reported as a success, but the form only substituted it into a `select`, so half the providers the feature advertises showed nothing. Free-text model fields now render the resolved list as `<datalist>` suggestions and the refresh repopulates them in place. The typed value is left alone: for self-hosted backends the list is a suggestion, not a constraint, which is why the field is free text to begin with.
- `mageos_ai/services/configuration` is now registered with `Magento\Config\Model\Config\TypePool` as `sensitive`, so `bin/magento app:config:dump` no longer writes stored provider credentials into `app/etc/config.php` (commonly committed) and no longer makes the AI Configuration field read-only in the admin, which is what happens once a value is present in the deployment config.

- Admin form field lookups no longer build CSS selectors out of stored data. Row IDs, service codes and field names are POST array keys of the services config value and reach the browser unmodified; interpolated into a selector, one containing a double quote produced an invalid selector, and `querySelector` threw a SyntaxError that aborted the calling loop and left the form half-rendered. Lookups now match the `name` attribute in JavaScript, so the only selector used is a constant.
- A network failure (connection refused, DNS failure, connection reset, timeout) now throws `AiTransientException` instead of the generic `AiServiceException`. Neither symfony/ai-platform nor its bridges catch the HTTP client's `TransportExceptionInterface`, so `Model\Client\AiExceptionMapper` never recognized it, and a consumer retrying `AiTransientException` did not retry a dropped connection. The mapper now matches that interface, in a buffered call and during a stream alike.

### Removed
- The `xai` service (xAI / Grok). Symfony AI has no xAI bridge, so the provider could never be used through the bundled client or Test Connection; it is requested upstream in symfony/ai#2371 but unclaimed. Shipping it meant offering a provider in the admin form with nothing an administrator could install to make it work. It can be reinstated as soon as a bridge is released. Installs with an `xai` row configured keep it in `core_config_data` until the AI Configuration page is next saved, at which point the row is dropped along with its credential.

- Duplicate `Grok` service (`grok`): xAI's models are named Grok, so it duplicated the `xai` service. Use `xai`, now displayed as "xAI (Grok)".

## [1.0.0] - 2026-04-21

### Added
- Structured `FieldDescriptorInterface` config field schema replacing the HTML-template pattern.
- `getSupportedModels(): array` method on each service for non-hardcoded model lists. Model lists ship as a curated baseline; admins may override per-install via a `<preference>` on each service class.
- GitHub Actions CI via `graycoreio/github-actions-magento2/check-extension`, matrix-targeted at `project: mage-os`.
- Unit test suite for `AiServiceSelector` (all four guards covered) and a parametrised smoke test exercising all eleven `AiServices/*` classes.
- Integration test covering round-trip of stored config through `ScopeConfigInterface`, with failure-safe cleanup in `tearDown()`.
- `AiServiceSelectorInterface` now documents its insertion-order contract.
- Admin form schema rendering hardens against HTML injection (client-side `escapeHtml()`) and preserves legacy stored values when the model list changes.

### Changed
- **BREAKING:** `AiServiceConfigurationInterface::getConfigurationTemplate(): string` replaced by `::getConfigurationFields(): FieldDescriptorInterface[]` and `::getSupportedModels(): array`.
- `composer.json` now pins `php: ^8.2` and `magento/framework: ^103.0 || ^104.0`.
- `Model/AiServiceSelector` hardened against null scope values and malformed JSON.
- `module.xml` declares explicit dependency on `Magento_Config` + `Magento_Backend`.
- `Block\Adminhtml\Configuration\Services` now validates at runtime that injected services implement `AiServiceConfigurationInterface`. (Classes are intentionally not `final` so Magento can generate interceptors/proxies.)

### Fixed
- `README.md` API example now references the correct `AiServiceSelectorInterface` (previously cited `AiServiceConfigurationInterface`).

[Unreleased]: https://github.com/mage-os-lab/module-ai-base/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/mage-os-lab/module-ai-base/releases/tag/v1.0.0
