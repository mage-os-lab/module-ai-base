# Architecture

How `MageOS_AiBase` is put together: components, data flows, storage formats, and the
security model. Audience: contributors and integrators who need to know *why* things are
shaped the way they are. For task-oriented guides see
[PROVIDERS.md](PROVIDERS.md) (integrating/customizing) and [CONSUMING.md](CONSUMING.md)
(using the module from other code).

## Component map

```
Api/
  AiServiceSelectorInterface        read configured services (consumer API)
  AiClientInterface                 provider-agnostic AI client: chat, streamChat, complete
  AiClientFactoryInterface          builds clients from saved config (consumer API)
  ChatRequestBuilderInterface       assembles a request without naming a Model class
  PlatformAwareInterface            opt-in escape hatch to the raw symfony/ai Platform
  ModelListProviderInterface        opt-in live model listing (provider SPI)
  UsageStatsInterface               the one read contract behind the dashboard and the CLI report
  UsageRecordRepositoryInterface    persistence for the raw mageos_ai_usage_log table
  UsageDailyRepositoryInterface     persistence for the mageos_ai_usage_daily roll-up table
  Data/
    AiServiceConfigurationInterface describes an available backend (provider SPI)
    AiServiceInterface              a configured instance (row id + code + values)
    FieldDescriptorInterface        one admin form field (name/label/type/options/default/encrypted)
    ChatRequestInterface            conversation plus offered tools (immutable)
    ChatResponseInterface           text, tool calls, usage, finish reason
    FinishReason                    why the model stopped, normalized across providers
    ChatMessageInterface            one turn; roles via the MessageRole enum
    ToolDefinitionInterface         a tool offered to the model
    ToolCallInterface               one invocation the model asked for
    TokenUsageInterface             prompt/completion/total, every count nullable
    StreamChunkInterface            one streamed event, typed by StreamChunkType
    UsageRecordInterface            one recorded call, before or after it is persisted
    UsageTotalsInterface            summed token counts for a period or a breakdown row
    UsageBreakdownInterface         one grouped row (a consumer, a service, a time bucket) plus its totals
    Period                          an immutable [start, end) UTC window, resolved from the store timezone
    Granularity                     Day | Month, how getTimeSeries() buckets a period

AiServices/                         bundled providers (OpenAi, Anthropic, Azure, ...)
  FieldFactoryTrait                 shared field builders (api_key, model, base_url, ...)
  ModelListTrait                    shared OpenAI-shape model list parsing / base-URL resolution

Model/
  AiServiceSelector                 parses stored JSON -> AiServiceInterface[] (decrypts, memoized)
  ServiceRegistry                   the registered backends, keyed by code (one di.xml list)
  FieldDescriptor, AiService        value objects behind the Data interfaces
  Config/
    SensitiveDataProcessor          encrypt/decrypt/mask/restore per service schema
    Backend/EncryptedServices       config backend model (save/load hooks)
    Source/ConfiguredService        option source for consumer modules' own system.xml fields
    Source/ConfiguredServiceWithAutomatic  the same, plus an empty-valued "Automatic" option
  Chat/                             value objects behind the chat contracts
    ChatRequestBuilder              the builder behind ChatRequestBuilderInterface
  Client/
    BridgeRegistry                  service code -> bridge factory, package and option dialect
    ClientFactory                   maps service code -> symfony/ai bridge, builds clients
    OptionNormalizer                universal options -> the target provider's own names
    SymfonyAiClient                 adapter around a symfony/ai Platform
    RecordingAiClient               decorator that writes one usage row per completed call
    RecordingPlatformAwareAiClient  the same decorator, for a delegate that is also PlatformAwareInterface
  ModelList/
    HttpFetcher                     shared HTTP/JSON plumbing for model fetching
    Storage                         persists fetched lists per service code
    Resolver                        stored list ?? curated getSupportedModels()
  Usage/
    UsageConfig                     typed reader for the mageos_ai/usage/* config group
    UsageRecord                     value object behind UsageRecordInterface
    UsageRecordRepository, UsageDailyRepository   implementations of the two Api repositories
    UsageStats                      implementation of UsageStatsInterface; merges raw + daily
    UsageMaintenance                the roll-up/prune sequence the cron job runs
    UsageMaintenanceResult          what one UsageMaintenance::run() did, for the cron's log line
    UsageTotals, UsageBreakdown     value objects behind the two Data interfaces of the same shape
    Source/Consumer, Source/ServiceRow   admin grid filter option sources
    Graph/SvgRenderer, Graph/DataPoint   the dashboard's inline bar and trend charts
  ResourceModel/Usage/
    UsageLog, UsageDaily             the SQL behind the two repositories above (see their own
                                      docblocks for why the SQL lives here and not in the repository)

Block/Adminhtml/
  Configuration/Services             admin form frontend model (schema JSON for JS)
  Usage/Dashboard                    prepares totals/breakdowns/trend for the usage dashboard
  Usage/RetentionNotice              states the configured retention above the grid
Controller/Adminhtml/
  Service/Test                       Test Connection endpoint (JSON)
  Service/RefreshModels              manual model list refresh endpoint (JSON)
  Usage/Index                        the Reports > AI Token Usage admin page
Cron/
  RollUpUsage                        scheduled entry point for UsageMaintenance
Console/Command/
  UsageReport                        `bin/magento mageos:ai:usage`
```

## Data flows

### Save path (admin form → database)

1. The form is an `AbstractFieldArray`; rows are built client-side from a schema JSON
   emitted by the Block (per service: `fields` descriptors + a `supportsModelRefresh` flag).
2. POST hits `EncryptedServices` (the `backend_model` in `system.xml`):
   - `restoreRow()` — any submitted `******` placeholder is replaced by the previously
     stored (still encrypted) value for that row/service/field, so saving without retyping
     keeps credentials. Row identity relies on the form reusing stored row IDs.
   - `encryptRow()` — descriptor-flagged fields are encrypted with Magento's
     `EncryptorInterface`. Encryption is idempotent: values already carrying the encryptor
     envelope (`N:N:...`) are left alone.
3. `ArraySerialized` serializes rows to JSON at `mageos_ai/services/configuration`.

### Read path (database → consumers)

`AiServiceSelector::getAll()/getByCode()/getById()` reads the path with store scope,
defensively parses (non-string raw, malformed JSON, malformed rows, non-string codes are all
skipped, never thrown), decrypts flagged fields via `SensitiveDataProcessor`, and wraps each
row in an `AiServiceInterface`. Consumers always receive plaintext values.

The JSON object key of each row becomes `AiServiceInterface::getId()`. That key is the row id
Magento's `AbstractFieldArray` assigns and the form preserves across saves, which is what makes
it safe for another module to store as a reference to "the service the administrator picked"
(`Model\Config\Source\ConfiguredService` → `getById()` / `AiClientFactoryInterface::createById()`).
The service code cannot fill that role, because the same backend may be registered several times
with different credentials or models. A row deleted in the admin makes stored ids stale by
design: the selector answers `null` and the client factory throws, rather than resolving to a
different row and billing an account nobody chose.

### Admin display path

`EncryptedServices::_afterLoad()` masks flagged fields with `******` — plaintext
credentials never reach the page DOM. The form JS forces `password` inputs for
encrypted fields regardless of their declared type.

### Client path

`ClientFactory::create(?code)` → first matching configured service → resolves the bridge
FQCN from `BridgeRegistry` → `class_exists`/`method_exists('createPlatform')` guards → builds a
`SymfonyAiClient` carrying the platform, the model, the service code and the row id. All
symfony/ai references are lazy (string FQCNs); the module compiles and runs without the
package installed.

Per call, `SymfonyAiClient` runs the caller's options through `OptionNormalizer` before handing
them to the platform. Bridges merge options into the provider's request body nearly untouched
and OpenAI-compatible endpoints reject unknown body fields with a 400, so the same
`max_tokens` is `max_output_tokens` on the Responses API, `maxOutputTokens` on Gemini and
`num_predict` on Ollama, and Anthropic rejects a request that omits it entirely. Which shape a
provider speaks is the `dialect` on its `BridgeRegistry` entry; the dialects themselves are
di.xml data, so a third party registering a provider declares one alongside its bridge. Only the
universal four are touched — everything else reaches the provider verbatim.

Token usage is normalized the same way, once, per bridge: `SymfonyAiClient` hands every raw
`TokenUsage` object it reads off a result (or a stream delta) to `Model\Client\UsageNormalizer`
before wrapping it as this module's own `TokenUsage`. Bridges disagree on whether a cache read or
write is already counted inside the prompt figure they report. Anthropic's Messages API excludes
`cache_read_input_tokens` and `cache_creation_input_tokens` from `input_tokens`, while every other
bundled bridge already counts what it reports as cached inside its own prompt count. Left alone, a
provider counting cache outside its prompt would under-report both the prompt and the total by
exactly what it billed for the cache. `UsageNormalizer` reads which behavior a bridge has off
`BridgeRegistry::isCacheOutsidePrompt()` rather than hardcoding a provider name: a bridge entry
that sets `cache_outside_prompt` (boolean, or a string `di.xml` can also parse as one) to `true`
gets its cache read and write counts folded into the prompt and dropped from the reported total
(so `TokenUsage::getTotalTokens()`'s own prompt-plus-completion fallback recomputes it correctly);
one that leaves the flag out, or sets it `false`, is passed through unchanged. Registering a new
bridge whose provider behaves like Anthropic means adding that one flag to its `BridgeRegistry`
entry; every other bridge needs nothing here at all. See
[docs/USAGE-TRACKING.md](USAGE-TRACKING.md#token-counts) for what this means for a recorded row,
and its [provider table](USAGE-TRACKING.md#which-providers-report-what) for which bundled
providers set the flag.

Responses carry the stop reason both ways: `getFinishReason()` is a normalized `FinishReason`
(the platform's per-bridge mappers do the provider translation), `getRawFinishReason()` keeps the
provider's wording. `streamChat()` yields chunks and then *returns* the assembled
`ChatResponseInterface`, because the platform lifts final token counts and the stop reason out of
the delta sequence into result metadata — a client only watching deltas would report neither.

### Model refresh path (manual only)

Admin clicks Refresh Models → `RefreshModels` controller → the service's `fetchModels()`
(via `HttpFetcher`, Magento's HTTP client) → `Storage::save()` at
`mageos_ai/services/models/<code>` (which also cleans the config cache so the change is
live immediately) → response updates the form select in place.
`Resolver` is the single merge point: stored list if present, else the curated
`getSupportedModels()`. There is intentionally no cron/automatic fetching: no background
HTTP with credentials, no cache-invalidation policy, and the admin sees exactly when and
why a list changed.

### Recording path

`ClientFactory::buildClient()` wraps every client it builds with a usage-recording decorator,
unless `UsageConfig::isEnabled()` says tracking is off. Which decorator depends on the wrapped
client's own shape: `RecordingPlatformAwareAiClient` when it implements `PlatformAwareInterface`,
`RecordingAiClient` otherwise. A single decorator that always implemented
`PlatformAwareInterface` would lie to the documented `instanceof PlatformAwareInterface` check
(see ["Why there is an escape hatch anyway"](#why-there-is-an-escape-hatch-anyway) below)
whenever the wrapped client deliberately does not implement it.

`RecordingAiClient::chat()` resolves the model actually called (the caller's `OPTION_MODEL`
override, or the client's configured one) and the consumer to attribute (the caller's
`OPTION_CONSUMER` override, or the client's own `getConsumer()`), strips `OPTION_CONSUMER` before
forwarding the call — a third-party delegate has never heard of it and would otherwise forward it
straight into the provider's request body, which OpenAI-compatible endpoints reject with a 400 —
then writes one `UsageRecordRepositoryInterface::save()` call with the resolved model, resolved
consumer, the current store id (`0` when no store is in scope, which is what cron, CLI and
adminhtml already mean by that column elsewhere), and whatever the call produced. **A row is
written whether the call succeeded, failed after reaching the provider, or succeeded while
reporting no usage at all**: a successful response's `TokenUsageInterface` (`null` when the
provider reported nothing), or `null` usage with the `failed` flag set when the delegate threw.
`streamChat()` does the same after the generator finishes (or is abandoned by an early `break`,
recorded as not failed), from whatever the last `Usage` chunk reported. The one call
`RecordingAiClient` never writes a row for is one that threw `AiRequestNotSentException`: that
exception means the request was rejected before it ever reached the provider (an unsupported
option, an invalid model override, a malformed request the calling code built wrong), so nothing
was billed and there is nothing a usage table can honestly describe; it is rethrown unchanged,
unrecorded, before the delegate is even called. See
[docs/USAGE-TRACKING.md](USAGE-TRACKING.md#failed-calls-and-null-token-rows) for what a merchant
sees for each of these on the recorded side. A save failure is logged and swallowed, never thrown:
recording is a side effect of a call that already succeeded or already failed on its own terms,
and turning a storage problem into an exception here would override either outcome for the caller.

**`complete()` is reimplemented rather than delegated.** The wrapped client's own `complete()`
calls its own internal `chat()` — not this decorator's `chat()` — so delegating `complete()`
straight through would call the delegate's `chat()` directly and record nothing for it.
`RecordingAiClient::complete()` instead rebuilds the single-user-message `ChatRequestInterface`
itself and calls its *own* `chat()`, the same one every other entry point goes through, which is
what makes every entry point on `AiClientInterface` recorded exactly once regardless of which one
a consumer called.

Calls made directly against `PlatformAwareInterface::getPlatform()` bypass this decorator
entirely and are therefore **untrackable by design** — see the decision record below.

### Roll-up path

`Cron\RollUpUsage`, scheduled by `etc/crontab.xml` against `mageos_ai/usage/cron_expr`, checks
`UsageConfig::isEnabled()` itself (rather than trusting `UsageMaintenance::run()`'s own no-op) so
a disabled install never starts a run at all, then delegates everything to
`Model\Usage\UsageMaintenance::run()` and logs the result (or the failure, which it also
rethrows, since there is no live admin request behind a cron run to protect the way there is
behind a chat call).

`UsageMaintenance::run()` is two independent phases, in a fixed, non-negotiable order:

1. **Roll up, then delete, one whole local day at a time**, oldest first, for every day strictly
   older than `retention_days` and at or after the oldest row still in `mageos_ai_usage_log`.
   Each day's window is resolved once in the store timezone
   (`TimezoneInterface::getConfigTimezone()` plus plain `\DateTimeImmutable`/`\DateTimeZone`
   arithmetic — never SQL's `DATE()` or `CONVERT_TZ()`, so a MySQL instance with no timezone
   tables loaded still gets the right boundary and a daylight-saving transition still produces
   exactly one 23- or 25-hour bucket) and converted to a UTC `[start, end)` pair.
   `UsageRecordRepositoryInterface::aggregateRange()` produces one row per
   (`service_id`, `service_code`, `model`, `consumer`, `store_id`) grouping key for that day, which
   `UsageDailyRepositoryInterface::saveAggregates()` writes with an insert-or-update that
   *replaces* an existing row's counts rather than summing onto them — the whole day is always
   recomputed from the raw rows still present, so summing would double-count a day rolled up twice
   after a partial failure. Only once that write has succeeded does
   `UsageRecordRepositoryInterface::deleteOlderThan()` remove that day's raw rows, in bounded
   batches (a store with months of history should not hold a lock for minutes deleting it in one
   statement). Roll-up before delete is the one ordering rule this class exists to enforce: reversed,
   a failure between the two steps would be silent data loss instead of a day simply rolled up
   again next run.
2. **Prune daily rows** older than `daily_retention_days`, independent of whatever phase 1 did:
   a store can have a stale daily backlog even on a run that rolled up nothing new.

A store that switches tracking off keeps whatever history it already collected — `run()` is a
no-op reporting all zeros rather than touching either table, since the toggle governs recording,
not retention of what was already recorded.

## Storage formats

| Path | Content |
|---|---|
| `mageos_ai/services/configuration` | JSON `{rowId: {serviceCode: {field: value}}}`; flagged fields encrypted |
| `mageos_ai/services/models/<code>` | JSON `{models: {value: label}, fetched_at: <ts>}` from the last manual refresh |
| `mageos_ai_usage_log` table | One row per call the client actually sent: succeeded, failed after reaching the provider, or succeeded reporting no usage. Never for a call `AiRequestNotSentException` rejected before it reached the provider. Columns: service id/code, model, consumer, store id, six independently-nullable token counts (prompt, completion, total, cache read, cache write, reasoning), whether it streamed, whether it failed, `created_at`. Counts and metadata only, see the decision record below |
| `mageos_ai_usage_daily` table | One row per (`usage_date`, service id, model, consumer, store id) grouping key per day, written by the roll-up cron; `usage_date` is a store-timezone calendar date, not `DATE(created_at)`. Carries the same six token counts plus `calls` and `failed_calls` |

Row IDs are opaque strings generated by the form (`_<time>_<ms>`) and preserved across
saves so credential restore can match rows.

## Security model

- **Encryption at rest**: descriptor-flagged fields (`'encrypted' => true`) via
  `EncryptorInterface`. Schema-driven; a name heuristic (`apikey`/`api_key`/`token`/`secret`)
  applies only to rows whose provider class is no longer registered (defense in depth for
  removed third-party modules).
- **No plaintext in the admin**: masked on load, restored on save (see flows above).
- **Legacy tolerance**: values without the encryptor envelope are treated as plaintext and
  pass through reads unchanged; they get encrypted on the next admin save.
- **CSP**: all form JavaScript is emitted through `SecureHtmlRenderer` (hash/nonce), safe
  under strict admin CSP (Magento 2.4.7+).
- **Endpoints**: `Service\Test` and `Service\RefreshModels` are POST-only, form-key validated
  (enforced by the `Backend\App\AbstractAction` plugin chain — which is why they extend
  `Backend\App\Action` rather than using pure composition), and gated by the
  `MageOS_AiBase::configuration` ACL. `Usage\Index` is the module's one read-only page controller
  (`HttpGetActionInterface`), gated by the separate `MageOS_AiBase::usage` ACL instead, since
  looking at recorded usage is a different permission from changing provider configuration.
- **Outbound calls**: happen only on explicit admin action (Test Connection, Refresh Models)
  or when a consumer module invokes the client API. The module itself never calls providers
  in the background. Usage recording never calls a provider either: it only ever writes a row
  after a call the consumer already made has answered.

## Decision record: symfony/ai dependencies

The client layer adapts [symfony/ai-platform](https://github.com/symfony/ai) rather than
hand-rolling per-provider HTTP clients. The **OpenAI and Anthropic bridges are hard
requirements** (pinned `^0.13`); every other bridge stays under `suggest`.

Originally every symfony/ai package was a soft dependency, for three reasons: installability
(symfony/ai-platform needs Symfony 7.3+ components, which older Magento releases cannot
resolve), churn isolation, and pay-for-what-you-use. The first and third were later traded
away for out-of-the-box usability: a base module whose two most common providers need a
second, manual `composer require` before anything works is a worse default than one that
narrows its installable range. Requiring the two bridges means a fresh install can talk to
OpenAI and Anthropic immediately, at the accepted cost that config-registry-only consumers
carry the SDK and that the module only installs where the dependency graph allows Symfony
7.3+ components. The `magento/framework` constraint is narrowed to `^103.0.7 || ^104.0`
(Magento 2.4.7+) to state that floor honestly: 2.4.6's Symfony 5.4 line cannot resolve next
to symfony/ai-platform, while 2.4.7 and 2.4.8 can.

**Churn isolation still stands unchanged.** The component is experimental with no BC promise.
Its README says so outright: *"This Component is experimental. Experimental features are not
covered by Symfony's Backward Compatibility Promise."* That is not hypothetical on the surface
a tool loop touches most: its changelog reworked `Message::ofAssistant()` in 0.9 and
`Message::ofToolCall()` in 0.11, 0.12 moved every bridge into its own package, and 0.13
added `ListenerInterface::onError()`. The adapter (`SymfonyAiClient` + `ClientFactory`)
quarantines that churn to two classes; signatures are verified against **v0.13.0** and must be
re-verified on upgrade — which is why the require is pinned to `^0.13` rather than left open.

Consequences: consumers depend on `AiClientInterface` only; bridges are still FQCN strings
resolved lazily with guards, because the nine non-required providers remain optional and a
store may remove the required bridges via `replace`; native implementations can replace the
whole layer via a `<preference>` without touching consumers. Note that pure `class_exists`
checks on `*Factory` names are unreliable inside Magento test/codegen environments (factories
are auto-generated) — hence the additional `method_exists` guard.

### Decision: one bridge that is not Symfony's

`BridgeRegistry` names a factory class and a composer package per service code, and until OpenCode
every one of those was a `symfony/*` package. Upstream releases one bridge package per provider and
has released none for OpenCode, so the choice was between leaving the provider unusable through the
bundled client — configuration-only, which the registry already supports — and shipping the bridge.

It is shipped, as **`mage-os/library-ai-opencode-zen-platform`**: a plain PHP library, MIT like the
Symfony code it is built on, carrying no Magento code and needing no `setup:upgrade`. It is thin
because it can be: OpenCode Zen speaks the OpenAI Chat Completions body, so the bridge is
`symfony/ai-generic-platform`'s client pointed at Zen's host, plus a model catalogue. It stays a
`suggest` on this module, for the same pay-for-what-you-use reason every non-required bridge does.

Kept out of this module deliberately. Putting those two classes in `src/` would have made
`symfony/ai-generic-platform` a hard requirement of every install, including the ones that never
configure OpenCode, and would have buried a reusable Symfony AI bridge inside a Magento module
where no non-Magento project could reach it.

Its model catalogue enumerates nothing and accepts any model id, which is the opposite of every
Symfony bridge's frozen static list. Zen is a gateway: its catalogue turns over monthly and is
published as an endpoint, so a frozen copy would reject a model an administrator can see in the
gateway's own listing. `ClientFactory::createCatalog()` exists to paper over exactly that staleness
for the bridges that do freeze; here there is nothing to paper over, which is why the `opencode-zen`
entry registers no `catalog` at all.

The remaining limitation is deliberate and documented: Zen fans its catalogue out across
`/v1/chat/completions`, `/v1/responses`, `/v1/messages`, `/v1/models/<id>` and `/v1/systemone`,
and a `BridgeRegistry` entry carries exactly one request-option `dialect`. Routing per model family
inside the bridge would leave that one dialect wrong for half the models, so the bridge speaks Chat
Completions only and the other families fail against the gateway, with the gateway's own message.

### Decision: a stateful agent server as a stateless provider

`opencode-custom` points the bundled client at a self-hosted `opencode serve` instance. That server
is not a completion API: it is opencode's agent, reached over a session API (`POST /session`,
`POST /session/{id}/message`), answering through whichever providers it has configured, and by
default allowed to read, edit and run commands on the machine it runs on. The bridge
(`mage-os/library-ai-opencode-custom-platform`) maps one `AiClientInterface` call onto one
throw-away session:

- **Isolation first.** Each session is created with a deny-everything permission ruleset, and each
  prompt is sent with every tool switched off. Two switches rather than one, because the server
  (1.18.32) silently ignores request fields it does not recognise: a renamed option in a later
  release would turn one of them into a no-op without an error. Whether a real model is then
  actually unable to reach a tool **has not been verified against a live model**; both fields are
  asserted on the wire in the bridge's unit tests.
- **No state carried.** The session is deleted in a `finally`, success or not. Conversation history
  therefore travels inside the one prompt, flattened into a labelled transcript; system messages
  use the server's own `system` field.
- **Tool calling is emulated, not given up.** The first version refused any request carrying tools,
  which conflated the opencode *agent* running tools on its own host (off, and staying off) with the
  *model* asking the caller to run the caller's tools. That made the provider unusable for
  `MagoAssistant_Mago`, whose every turn is a tool loop. The bridge now describes offered tools in
  the system prompt and parses `<tool_call>` blocks back into real `ToolCall` objects, so the tools
  still execute in Magento with Mago's write confirmations, per-admin permissions and privacy
  scrubbing intact. Reliability is the model's: a model that ignores the format answers in prose,
  which is returned as text. Rejected alternative: the server's `format: json_schema` structured
  output, which it implements by injecting its own `StructuredOutput` *tool* — fighting the very
  switches that keep the agent harmless.
- **What it does give up.** Real streaming (the server answers once the agent has finished; a streaming caller gets
  the whole answer as one chunk), and every universal option: the message endpoint has no
  `max_tokens`, `temperature`, `top_p` or `stop`, so the `opencode_server` dialect maps none of
  them and lists all four under `ignore`: they are dropped before the request. Refusing them was the
  first design, and it broke every consumer that sets one without knowing which backend an
  administrator picked, this module's own Test Connection included. A dropped option can make an
  answer longer or less deterministic than asked for, never wrong; an option a provider cannot
  honour *and* whose loss would change the meaning of a call should stay refused.
- **What it gains.** The server has already done the provider integration: through one entry an
  administrator reaches Claude, GPT, and local models, with credentials that stay on the server.

Answers pass through the server's agent, so they carry its system prompt and the `AGENTS.md` of the
directory the server runs in unless the row names a plain agent (`agent` field). An upstream failure
is reported by the server as HTTP 200 with the error on the message; the bridge raises it as the
matching platform exception, so it reaches `SymfonyAiClient` exactly like any other bridge's error.

### Why there is an escape hatch anyway

`AiClientInterface` is chat, streaming and completion. symfony/ai-platform is much larger:
executed tool loops (`symfony/ai-agent`), message stores (`symfony/ai-chat`), structured output,
embeddings, vector stores, image and audio. Mirroring all of it would mean re-describing an API
that already exists, and every addition upstream would become a porting task here.

So `Api\PlatformAwareInterface` hands over the platform this module already built, with
credentials resolved and the bridge selected, and the module's unique contribution (admin UI,
credential encryption, multi-row configuration, row selection) stays the thing it owns.

The cost is real and is priced deliberately:

- It is a **separate interface**, reached by `instanceof`, not a method on `AiClientInterface`.
  The coupling is therefore visible at the call site, and a store preferencing its own client
  stack simply does not implement it rather than being forced to fake a Symfony platform.
- It returns `object`, not `PlatformInterface`, so the module's own API surface stays free of
  symfony/ai types: it remains loadable and compilable on an install that removed
  symfony/ai-platform via `replace` in favour of a native client stack.
- Everything past `getPlatform()` sits outside this module's compatibility promise, and both
  README.md and CONSUMING.md say so where a reader will meet it.
- `normalizeOptions()` rides along, because calling the platform directly otherwise silently
  opts out of the option translation described under "Client path" above. That is the one part
  of the client layer worth keeping when the rest is bypassed, so it is offered on its own
  rather than being reachable only through `chat()`.

Note the tool-execution boundary moves for anyone taking this route: this module never executes
tools (whether a call may run is policy belonging to the module owning the tool), while
`symfony/ai-agent`'s `Toolbox` does. That is a defensible place for the decision to live, but it
should be taken knowingly.

## Decision record: usage tracking scope

Recording lives in a decorator (`RecordingAiClient` / `RecordingPlatformAwareAiClient`, see
["Recording path"](#recording-path) above) rather than inside `SymfonyAiClient` itself, so it can
be switched off entirely with no trace in the call path when `mageos_ai/usage/enabled` is off, and
so a store that replaces the whole client layer via `<preference>` gets to decide for itself
whether recording exists at all. Four choices inside that scope are worth writing down, because
each one is a question a future reader — or a bug report — will otherwise re-raise from scratch.

**Counts only, never content.** `mageos_ai_usage_log` and `mageos_ai_usage_daily` store token
counts, a model name, a consumer identifier, a service id/code and a store id — never a prompt,
never a response, never a tool argument or result. Both table comments in `db_schema.xml` say so
directly. This is not an oversight to fix later: a usage table exists to answer "how much,
by whom, on what", and a table that also held conversation content would turn a spend-accounting
feature into a second, undifferentiated store of exactly the sensitive data
[`docs/CONSUMING.md`](CONSUMING.md#extending-behavior) already warns a *plugin* on `complete()`
to treat as sensitive. Nothing about this module's threat model changes for the better by
duplicating that risk into a table nobody thinks to encrypt or purge the way credentials are.

**Raw log plus daily roll-up, not the raw log alone.** A per-call row is what a 30-day
investigation ("which module burned through this month's Anthropic quota on Tuesday") needs, and
what a merchant open for years cannot keep forever — see the retention rationale in
[`docs/USAGE-TRACKING.md`](USAGE-TRACKING.md#retention). A daily aggregate alone would answer
"how much last month" forever but could never answer "which call" once it happened. Keeping the
raw table forever would answer both, at the cost of a table that grows without bound on every
store that leaves tracking on, which is worse than the alternative it is trying to avoid.
Aggregating once and pruning the detail behind it, in that order (see "Roll-up path" above), is
what lets `retention_days` stay short by default while `daily_retention_days` stays long.

**No cost estimation.** `UsageTotalsInterface` and the CLI report tokens, never a currency
amount. Provider prices change on their own schedule, differ per account tier and negotiated
contract, and are not knowable from a token count and a model name alone — this module has no
reliable source for any of that, and a cost figure computed from a stale or wrong price list would
read as more authoritative than the token count it was derived from, which a merchant can at least
cross-check against a provider's own bill. Estimating and then getting it wrong is a worse outcome
than not estimating at all, so the interface stops at what it can state as fact.

**The `getPlatform()` limitation.** ["Why there is an escape hatch anyway"](#why-there-is-an-escape-hatch-anyway)
already documents that everything reached through `PlatformAwareInterface::getPlatform()` sits
outside this module's own compatibility promise; the same boundary means it sits outside usage
tracking too. `RecordingPlatformAwareAiClient::getPlatform()` forwards to the wrapped client's
platform untouched, and a call made directly against the object that method returns never passes
through `RecordingAiClient::chat()`/`streamChat()` — there is no decorator method on the path for
it to go through. This is not a gap to close: recording would have to reach into symfony/ai's own
result types to intercept a call this module never sees invoked in the first place, which is
exactly the coupling `PlatformAwareInterface` exists to keep out of this module's own surface.
A consumer reaching for `getPlatform()` — most often for `symfony/ai-agent`'s executed tool
loop — is trading usage visibility for the platform features this module does not mirror, the
same trade it already makes for compatibility; both README.md and `docs/CONSUMING.md` note it
where a reader meets the escape hatch, and `docs/USAGE-TRACKING.md` states it again from the
merchant's side.

## Testing strategy

- Unit tests live in `Test/Unit`, run standalone (`vendor/bin/phpunit --testsuite Unit`)
  and in CI inside a full Mage-OS install (Graycore `check-extension`).
- Standalone runs show environmental errors for mocks of Magento-generated `*Factory`
  classes (they only exist in a full install) — these are expected; CI is authoritative.
  `Test/Unit/Stubs` provides a class_exists-guarded stand-in for
  `FieldDescriptorInterfaceFactory` so provider tests still run standalone.
- Tests needing `Magento\Config`/`Magento\Backend` classes guard-skip when those modules
  aren't autoloadable, so the standalone suite stays green-ish everywhere.
- One integration test covers the config round-trip through `ScopeConfigInterface`.
