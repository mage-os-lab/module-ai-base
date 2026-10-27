# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-10-27

### Added
- **Opper provider** (`opper`, `AiServices\Opper`): the [Opper](https://opper.ai) gateway is now
  one of the registered AI backends, with an API key and a free-text model field. The host is fixed
  at `https://api.opper.ai`, so the row needs no URL. **Refresh Models** reads the catalogue scoped
  to the API key from `/v3/compat/models`, without the embedding-only entries; there is no curated
  fallback list, since that catalogue includes routes specific to the organisation. It needs no
  bridge of its own: the `opper` entry on `Model\Client\BridgeRegistry` points the
  `symfony/ai-generic-platform` factory at Opper's `/v3/compat` chat and embeddings paths through
  constants in `etc/di.xml`.
- **OpenCode Zen provider** (`opencode-zen`, `AiServices\OpenCode`): the
  [OpenCode Zen](https://opencode.ai/docs/zen/) gateway is now one of the registered AI backends,
  with an API key, an optional base URL and a free-text model field, and **Refresh Models** reading
  the gateway's live listing at `{base_url}/v1/models` (which Zen serves unauthenticated, so the
  list can be populated before a key is pasted). Its bridge does not come from Symfony: upstream
  has released none for OpenCode, so it ships as `mage-os/library-ai-opencode-zen-platform`, built
  on `symfony/ai-generic-platform`, and stays a `suggest` like every other optional bridge. Zen
  serves only part of its catalogue from Chat Completions (DeepSeek, MiniMax, GLM, Kimi and the
  free tier); `gpt-*`, `claude-*`, `gemini-*`, `grok-*`, `qwen*` and `jev-*` live behind other
  endpoints with other request shapes and cannot be reached through this bridge, which is why the
  curated model list names only the former.
- **OpenCode Custom provider** (`opencode-custom`, `AiServices\OpenCodeCustom`): a self-hosted
  `opencode serve` instance as an AI backend, with base URL (default `http://127.0.0.1:4096`),
  username (default `opencode`), server password (stored encrypted under `api_key`), an optional
  agent and a free-text `providerID/modelID` model. **Refresh Models** lists every model the server
  has configured, from `GET /config/providers`. Its bridge, `mage-os/library-ai-opencode-custom-platform`,
  speaks the server's session API rather than Chat Completions: one throw-away session per call,
  created with every permission denied, and deleted afterwards. Tools offered by a consumer are
  described in the prompt and the model's calls parsed back out, so a tool loop works unchanged;
  the opencode agent's own tools stay off. No incremental streaming, and the universal
  `max_tokens` / `temperature` / `top_p` / `stop` options are **dropped** through the new
  `opencode_server` dialect, since the server's message endpoint accepts none of them and applies
  its own limits. Token counts come from the server's own per-message accounting.
- `OptionNormalizer` dialects can list universal options under a new `ignore` key: options the
  provider has no equivalent for and that are removed without an error, instead of raising
  `AiRequestNotSentException`. An unmapped option not listed there is still refused. Used by
  `opencode_server`, where refusing made the provider unusable for any consumer that caps its
  answers, including this module's own **Test Connection** (`max_tokens` = 16).
- A stored `base_url`, `username` and `agent` are passed by name to any bridge factory that
  declares a `baseUrl`, `username` or `agent` parameter (`Model\Client\ClientFactory::ROW_ARGUMENTS`),
  when the row holds a non-empty value. A hosted provider behind a proxy or an in-house gateway
  can so be reached without a bridge of its own. A parameter the provider already fills
  positionally through `getPlatformArguments()` is never named a second time: LM Studio's first
  parameter is itself spelled `baseUrl`, and naming it again is a fatal `Error`.
- `Model\Config\Source\ConfiguredService` and its `WithAutomatic` variant take a `serviceCodes`
  constructor argument. A consumer whose feature works with one provider only declares a virtual
  type with that argument set and points its `system.xml` field at it; the dropdown then lists
  only that provider's rows, and names the provider when none is configured. A consumer using it
  has to require this version or later: an older release ignores the argument silently. See
  "Restricting the choice to specific providers" in `docs/CONSUMING.md`.
- `i18n/en_US.csv` with every phrase the module shows, as a starting point for translations.
- The README covers getting started, every admin setting, where the module shows up, and
  troubleshooting.

### Changed
- Require symfony/ai-platform and the OpenAI and Anthropic bridges at `^0.15` (was `^0.14`).
- The Test Connection success line ("OK (%1ms): %2") is translatable.
- The composer package now ships `phpcs.xml.dist`, `phpunit.xml.dist` and `phpstan.neon`, so the
  tests can run from `vendor/`, and no longer ships `docs/`.
- A Base URL or Endpoint has to be an `http://` or `https://` URL. Saving the AI Configuration, or
  importing through `ServiceImporterInterface`, refuses anything else and names the field. A row
  already stored with another scheme has to be corrected before the form saves again.

### Security
- API key inputs use `autocomplete="new-password"`. Browsers ignore `off` on password fields, so
  the admin's own saved login could be autofilled into a key field and sent to the provider.
- Parameters that carry a decrypted credential are marked `#[\SensitiveParameter]`, so they are
  redacted from stack traces and error logs.
- CI runs with a read-only token, pins every action to a commit SHA, does not keep the checkout
  token on disk, and runs `composer audit` on its dependencies.

## [1.0.0] - 2026-10-08

The first stable release. Everything under `Api\` and `Exceptions\` is marked `@api`, with a note
on each type saying whether it is safe to implement or only to call; `docs/CONSUMING.md` has the
details under "What's stable".

### Added
- **Configuration.** An admin form at **Stores > Configuration > Mage-OS > AI Configuration** for
  OpenAI, Anthropic, Azure OpenAI, Google Gemini, DeepSeek, HuggingFace, LM Studio, Ollama,
  OpenRouter and OpenAI-Compatible (LiteLLM and other self-hosted gateways). The same provider can
  be added more than once; each row has an optional name, an enable toggle, Test Connection and
  Refresh Models (a live model list for OpenAI, Anthropic, Gemini, OpenRouter, Ollama and LM
  Studio). Rows can be set per website and store view. Production mode only offers providers whose
  Symfony AI bridge is installed; developer mode lists the rest with the `composer require` to run.
- **Reading configuration.** `AiServiceSelectorInterface` (`getAll()`, `getByCode()`, `getById()`)
  and the `ConfiguredService` / `ConfiguredServiceWithAutomatic` option sources, so another module
  can let an administrator pick a configured row and store its id.
  `ServiceImporterInterface` lets another module's data patch move its own saved API key into a
  configured row (`importFromConfig()` reads and decrypts its `obscure` field) and store the
  returned id; importing the same values twice returns the same row.
- **Client.** `AiClientFactoryInterface::create()` / `createById()` build an `AiClientInterface`
  backed by symfony/ai-platform, with `complete()`, multi-turn `chat()` and `streamChat()`. Tool
  calls are surfaced, never executed; reasoning is carried between tool-loop turns; a finished
  stream returns the assembled turn. `max_tokens`, `temperature`, `top_p`, `stop`, `tool_choice`
  and `reasoning_effort` are translated into each provider's own request format. Failures are
  typed (`AiAuthenticationException`, `AiRateLimitedException`, `AiTransientException`,
  `AiInvalidRequestException`, `AiContentFilteredException`, `AiToolCallException`), all
  extending `AiServiceException`, plus `AiRequestNotSentException` for a call refused before it
  reached the provider. Every one is a `LocalizedException`.
  `ChatRequestBuilderInterface` builds requests without touching `Model\` classes, and
  `PlatformAwareInterface` hands over the underlying Symfony AI platform for anything else.
- **Usage tracking.** Every call through the client is recorded (token counts and metadata, never
  content) per service row, model, consumer and store, rolled up daily by cron and pruned after a
  configurable retention. Shown at **Reports > AI Token Usage** as a dashboard and a grid, and by
  `bin/magento mageos:ai:usage`; read programmatically through `UsageStatsInterface`. Consumers
  name themselves with the `$consumer` argument or `AiClientInterface::OPTION_CONSUMER`.
- **Extensibility.** Providers extend `AiServices\AbstractAiService` and register in one `di.xml`
  list (`Model\ServiceRegistry`). Bridges and their request-option dialects are `di.xml` data on
  `Model\Client\BridgeRegistry` and `Model\Client\OptionNormalizer`. Optional capabilities:
  `ModelListProviderInterface` (live model lists, through `JsonFetcherInterface`) and
  `PlatformArgumentsProviderInterface` (bridge factories that take more than an API key).

### Changed
Since v0.0.1:
- symfony/ai-platform and the OpenAI and Anthropic bridges are required at `^0.14`; the other
  bridges remain a `suggest` at the same line.
- Client exceptions live in `MageOS\AiBase\Exceptions`, including `AiRequestNotSentException`
  (was `Model\Client`).
- Providers extend `AbstractAiService`, which replaces `FieldFactoryTrait` and gives every method
  added to `AiServiceConfigurationInterface` later a default. Bridge factory arguments come from
  the provider instead of a switch in `ClientFactory`.
- `PlatformAwareInterface::getPlatform()` returns `PlatformInterface`. `StreamChunk`, `ToolCall`
  and `TokenUsage` implement `JsonSerializable` instead of `getData()`. The usage repositories'
  reporting methods are internal; the table layout is not public API.
- `StreamChunkType` gained `ThinkingStart` and `ToolCallStart`; a `match` without a default arm
  needs updating. A response cut off at the token limit returns with `FinishReason::Length`
  instead of throwing.
- Refreshed model lists are stored per row, so two rows of one provider on different hosts keep
  their own list. Test Connection, Refresh Models and the option sources act on the scope being
  edited.
- Curated fallback model lists updated: GPT-5.5, GPT-5.4 (mini, nano) and GPT-4.1 for OpenAI and
  Azure, Gemini's `-latest` aliases, and version-neutral labels for DeepSeek's two aliases.
- The configuration ACL resource moved from Stores > Attributes to Stores > Configuration. Its id
  is unchanged, so existing role grants keep working.

### Removed
- The `xai` and `grok` providers. Symfony AI has no xAI bridge, so neither could ever be called.
  A stored `xai` row is kept, marked as having no installed provider, and can never be called;
  delete it with its own delete button in AI Configuration.

### Fixed
- A stream cut off at the output token limit keeps the signed reasoning it completed, so a tool
  loop can replay the truncated turn ([#63](https://github.com/mage-os-lab/module-ai-base/issues/63)).
- Gemini calls that set `tool_choice` were rejected with a 400; tool schemas with no properties
  are sent as a JSON object.
- A per-call model override on Azure, which always runs its configured deployment, now throws
  `AiRequestNotSentException` instead of being ignored while usage recorded the wrong model. A
  model the platform cannot route also throws it, without writing a failed usage row.
- Network failures map to `AiTransientException`; `ChatRequestBuilder::withAssistantTurn()` keeps
  the reasoning on the replayed turn.
- Usage from cron and CLI is recorded against store 0 (shown as "Admin, cron and CLI"), days are
  bucketed in the default-scope timezone, the daily roll-up adds to an existing day under a lock
  instead of overwriting it, and two rows of one provider get their own trend line.
- The AI Configuration form works at website and store scope, refuses an empty save from a form
  whose script did not finish rendering, and shows the deployment-lock warning only when the
  value is actually locked.
- Saving the AI Configuration no longer deletes rows whose provider module was removed or
  disabled ([#65](https://github.com/mage-os-lab/module-ai-base/issues/65)). They are kept as
  stored, credentials included, and shown as a read-only placeholder with a delete button; they
  work again with the same row id once the provider is back.

### Security
- The bundled client never follows an HTTP redirect, so a provider credential sent in its own
  header (Azure's `api-key`) cannot be carried to another host by a 3xx response
  ([#64](https://github.com/mage-os-lab/module-ai-base/issues/64)).
- Credentials are encrypted at rest, masked as `******` in the form, dumped to `env.php` rather
  than the commonly committed `config.php`, and re-encrypted when the encryption key is rotated.
- A stored key is dropped when a row's endpoint changes in the same save, for any field a provider
  flags as an endpoint and for any field named `base_url` or `endpoint`, so a key cannot be
  redirected to another host ([#62](https://github.com/mage-os-lab/module-ai-base/issues/62)).
  Refresh Models takes the provider from the stored row, not from the request.
- Test Connection and Refresh Models log provider and HTTP client errors instead of echoing them
  into the page, where a URL could carry a token ([#52](https://github.com/mage-os-lab/module-ai-base/issues/52)).
- The credential-name fallback for rows of an uninstalled provider recognises far more names
  ([#53](https://github.com/mage-os-lab/module-ai-base/issues/53)).
- Everything the form embeds in its script is JSON-encoded with `JSON_HEX_TAG` and friends, and
  the script is rendered through `SecureHtmlRenderer` for CSP.

### Upgrading from 0.0.1
- Requires Magento 2.4.8+ or Mage-OS 1.1+, and PHP 8.2+. A standard Magento 2.4.7 project cannot
  install it: its template requires MFTF 4.7, whose symfony/event-dispatcher ^6.4 conflicts with
  the ^7.3 symfony/ai-platform needs.
- `composer update mage-os/module-ai-base --with-dependencies`, and bump any suggested bridges you
  installed to `^0.14`.
- `bin/magento setup:upgrade` and `bin/magento setup:di:compile`. The usage tables are unchanged.
- Model lists refreshed under 0.0.1 are still read as a fallback; click Refresh Models on each row
  to move it to its own list.
- Custom code: catch the client exceptions from `MageOS\AiBase\Exceptions\` instead of
  `Model\Client\`, extend `AbstractAiService` instead of using `FieldFactoryTrait`, and replace
  `getData()` on stream chunks with `json_encode($chunk)`.

[Unreleased]: https://github.com/mage-os-lab/module-ai-base/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/mage-os-lab/module-ai-base/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/mage-os-lab/module-ai-base/compare/v0.0.1...v1.0.0
