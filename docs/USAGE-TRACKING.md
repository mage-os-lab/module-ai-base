# AI Usage Tracking

What this module records about AI calls, what it deliberately does not, where to see it, and how
to configure it. Audience: merchants and administrators. For how the feature is built, see
[docs/ARCHITECTURE.md](ARCHITECTURE.md#recording-path); for the consumer-side API (naming your
module's calls), see [docs/CONSUMING.md](CONSUMING.md#naming-your-module-as-a-consumer).

## What is recorded

Every completed AI call made through `AiClientInterface` — by this module's own admin features
(Test Connection) and by every other module calling through `AiClientFactoryInterface` — writes
one row:

- which configured service and provider handled it
- which model it actually ran against (see [Model attribution](#model-attribution) below)
- which module or feature it is attributed to (the **consumer**)
- which store it ran under
- prompt, completion, total, cache read, cache write and reasoning token counts (see
  [Token counts](#token-counts) below)
- whether the response was streamed
- whether the call failed, and whether it reported any usage before failing (see
  [Failed calls and null-token rows](#failed-calls-and-null-token-rows) below)
- when it happened

## Token counts

Six counts, each independently nullable. A provider that never mentions a count leaves it `null`
on the row, not `0`: `0` means the provider stated the count was zero, `null` means the provider
never reported it at all. The same distinction is drawn everywhere this module sums or averages
these counts, see [Failed calls and null-token rows](#failed-calls-and-null-token-rows).

- **Prompt**: tokens the call's input cost, **already including whatever the provider served from
  its cache.** This is normalized, not just observed: some providers report cache tokens as part of
  the prompt count to begin with, but at least one bundled provider's own API (Anthropic's) defines
  its prompt count as excluding both a cache read and a cache write, and this module folds them back
  in before a row is ever written. A prompt count therefore always means "what this call's input
  actually cost", the same way on every provider, rather than a figure whose meaning shifts with
  which backend answered. See [Which providers report what](#which-providers-report-what) below for
  which providers needed that correction.
- **Completion**: tokens the response produced, including any reasoning tokens spent getting there.
- **Total**: the provider's own total when it reports one, or prompt plus completion when it does
  not.
- **Cache read**: the part of the prompt served from the provider's cache instead of freshly
  processed. A subset of the prompt count above, never an addition to it.
- **Cache write**: the part of the prompt newly written into the provider's cache, for a later call
  to read back. Also a subset of the prompt count, kept apart from cache read rather than folded into
  one combined "cached" figure, because the two are typically billed at different rates. A cache
  write commonly costs a premium over a fresh prompt token, and a cache read commonly costs a
  fraction of one. This module states counts, never a computed cost (see
  [What is never recorded](#what-is-never-recorded) below): check the provider's own pricing page
  for the rates that actually apply to an account.
- **Reasoning**: the part of the completion the model spent on internal reasoning before writing its
  visible answer, for the providers and models that report it separately. A subset of the completion
  count, not an addition to it.

### Which providers report what

Not every provider reports every count, and this module states only what it has actually verified
against an installed bridge. The **Anthropic** and **OpenAI** bridges are hard requirements of this
module and are exercised by its own test suite; every other bridge is optional, and most are not
installed in this repository's own development environment, so their row below says so plainly
rather than guessing.

| Provider | Reports usage | Cache read | Cache write | Reasoning tokens |
|---|---|---|---|---|
| Anthropic | Yes | Yes, reported outside the prompt count by the provider's own API, folded back into the normalized prompt count by this module (see [Token counts](#token-counts) above) | Yes, same treatment as cache read | Not reported by the bridge this module uses |
| OpenAI | Yes | Yes, already counted inside the prompt as the provider reports it | Not reported by the bridge this module uses | Yes, when the model itself reports reasoning tokens (its extended-thinking-style models) |
| Azure (OpenAI) | Not verified | Not verified | Not verified | Not verified. Azure's bridge speaks the same request shape as OpenAI's, so the same behavior is expected, but the bridge package is not installed here to confirm it |
| DeepSeek, LM Studio, OpenRouter, OpenCode Zen (OpenAI-compatible bridges) | Not verified | Not verified | Not verified | Not verified. Each depends on what that specific endpoint's own Chat Completions response reports. For OpenCode Zen that is per model, since it fronts several providers behind one endpoint |
| HuggingFace | Not verified | Not verified | Not verified | Not verified. An earlier review of this feature raised that this bridge reports no usage at all on any call; that claim has not been independently confirmed against the bridge itself and should not be read as settled fact |
| Google (Gemini) | Not verified | Not verified | Not verified | Not verified. An earlier review of this feature raised that this bridge reports no usage specifically on a streamed call; that claim has not been independently confirmed against the bridge itself and should not be read as settled fact |
| Ollama | Not verified | Not verified | Not verified | Not verified |
| OpenCode Custom (self-hosted opencode server) | Yes, read off the server's own per-message count | Yes, as the server reports it | Yes, as the server reports it | Yes, as the server reports it. Whether the server's input count already includes cache reads follows its own accounting across upstream providers and has not been verified. The count covers the whole agent turn the server ran, including its own system prompt |

Whatever a provider does not report, this module never invents. A call through a provider that
reports nothing at all still writes a row, with every token count `null` on it, the same as any
other call that happened to report no usage; see the next section.

## Failed calls and null-token rows

A row is written for every call the client actually sends, whether it succeeds, fails after
reaching the provider, or succeeds while reporting no usage at all:

- A call that reached the provider and then threw, a dropped connection, a provider-side error,
  is recorded with the failed flag set. Its token columns still hold whatever the provider had
  already reported before the failure, when it reported anything; a failed call is not the same
  as a call with no usage, since a provider can bill (and report) partial usage on a call that
  still ends in an error. This is why "failed" is its own flag rather than something inferred from
  null token counts.
- A call that is rejected **before** it ever reaches the provider, an unsupported option, an
  invalid model override, a malformed request built by the calling code, is not recorded at all.
  Nothing was sent, so nothing was billed, so there is no call for a usage table to describe. See
  [docs/CONSUMING.md](CONSUMING.md#failure-modes-to-handle) for `AiRequestNotSentException`, which
  marks exactly this case.
- A streamed call that a caller abandons partway through, breaking out of the loop before the
  provider finished, is recorded as not failed, with whatever the last usage chunk it saw
  reported: the client itself never threw, the caller simply stopped listening.

`null` versus `0` carries through every total and average this module computes, not only the raw
row: a provider that never reports cache tokens does not drag a mixed-provider average toward
zero, and a period where nothing reported reasoning tokens shows "not reported" rather than a
reassuring-looking zero.

## What is never recorded

**No prompt or response content is ever stored.** Not the question asked, not the model's answer,
not a tool call's arguments or result. Only token counts and the metadata listed above. This is a
deliberate scope, not an omission: a usage table exists to answer "how much AI usage happened, by
whom, on what", and a table that also held conversation content would turn a spend-accounting
feature into a second, unencrypted store of exactly the kind of data a store already has to be
careful with elsewhere. See the "Counts only, never content" decision record in
[docs/ARCHITECTURE.md](ARCHITECTURE.md#decision-record-usage-tracking-scope) for the full
reasoning.

Two other things it does not do:

- **No cost estimate.** Every figure shown is a token count, never a currency amount. Provider
  prices change constantly and differ per account and negotiated contract, so a computed cost
  would be a guess dressed up as a fact. Cross-check token counts against the provider's own
  billing for actual spend.
- **Calls that bypass this module's client are invisible to it.** A module reaching past
  `AiClientInterface::getPlatform()` (`PlatformAwareInterface`, the documented escape hatch to the
  raw symfony/ai platform) makes calls this module never sees, so they are never recorded. That is
  the same trade-off the escape hatch already makes for API compatibility — see
  [docs/ARCHITECTURE.md](ARCHITECTURE.md#why-there-is-an-escape-hatch-anyway).

## Where to see it

**Reports > AI Token Usage** in the admin menu (ACL resource `MageOS_AiBase::usage`).

![The AI Token Usage dashboard: total tokens for the month with the change against the same span of the previous month, a trend chart with one line per consumer above a legend and a By consumer / By service selector, and bar charts breaking the period down by consumer and by service](images/admin-usage-dashboard.png)

The page has two parts:

- **A dashboard** at the top:
  - **Total tokens** for the selected period — today, this month or this year — and the change
    against the *same elapsed span* of the previous period. On the fourth of a month that compares
    four days against the first four days of the month before, not against the whole of it, so a
    period turning over does not read as a collapse in spend. Nothing is shown when the previous
    span recorded no usage at all, since a percentage against zero says nothing.
  - **A trend**, one point per day for a period of a month or less and one per month for a year.
    It draws a line per consumer, or per service row, switched with the **By consumer / By
    service** selector above it; the busiest five get a line of their own and everything behind
    them sums into a single **Other** line. Hovering anywhere in a day's column shows that day's
    exact figure for every line at once. The trend stops at the current moment rather than running
    to the end of the calendar period, so the remainder of an unfinished month is not drawn as a
    fall to zero.
  - **Two bar charts** breaking the period down by consumer and by service row.
- **A grid** below it, listing individual calls with their date, consumer, service, model and
  token counts, filterable and sortable like any other admin grid.

A **store selector** sits beside the period one and narrows every number on the page, the trend
included, to a single scope. Alongside the store views it offers **Admin, cron and CLI**, which is
where every call made outside a storefront is recorded and on most installs is the bulk of the
traffic. A URL naming a store that no longer exists falls back to every store rather than to none,
so a stale bookmark shows a total that is too broad, which a reader can see, rather than an empty
page.

All three selectors carry the whole view, so a link to a particular period, trend and store can be
bookmarked or shared and reopens on the same thing.

See [Grid vs. dashboard: what "30 days" limits](#grid-vs-dashboard-what-30-days-limits) below for
why the grid does not go back as far as the dashboard does.

Merchants and scripts that would rather not open the admin can run the equivalent report from the
shell:

```bash
bin/magento mageos:ai:usage --period=month
bin/magento mageos:ai:usage --period=today --consumer=catalog_description_generator
bin/magento mageos:ai:usage --period=year --format=json
bin/magento mageos:ai:usage --period=month --store=1
```

`--period` is one of `today`, `month` (the default) or `year`; `--format` is `table` (the
default) or `json`, for a script to parse. `--store` takes a store id and narrows the report the
way the dashboard's store selector does; left out, the report covers every store. The CLI reads
through the same `UsageStatsInterface` the dashboard does, so the two can never disagree about a
period's totals.

## Configuration

**Stores > Configuration > Mage-OS > AI Configuration > Usage Tracking.** The group opens with a link
straight to the report, shown only to a role the report's own ACL would let in.

| Field | Config path | Default | What it does |
|---|---|---|---|
| Enable Usage Tracking | `mageos_ai/usage/enabled` | Yes | Master toggle. Off means no row is ever written for a new call, and the cleanup cron does not run — see [Turning tracking off](#turning-tracking-off) below. |
| Raw Usage Retention (Days) | `mageos_ai/usage/retention_days` | 30 | How many days of individual calls the cleanup job keeps before rolling them up into daily totals and deleting the detail. Also what bounds the grid — see below. |
| Daily Usage Retention (Days) | `mageos_ai/usage/daily_retention_days` | 730 | How many days of daily totals the cleanup job keeps before deleting those too. Much longer than the raw retention, since this is what a long-running spend trend relies on once the per-call detail behind it is gone. |
| Cleanup Cron Schedule | `mageos_ai/usage/cron_expr` | `0 3 * * *` | The cron expression the roll-up/cleanup job runs on. Read directly by Magento's own cron scheduler through `crontab.xml`, not by this module at request time. |

## Retention

Two tables, two different lifespans:

- **`mageos_ai_usage_log`** holds one row per call. It is what the admin grid reads, and it is
  pruned down to the **Raw Usage Retention** window — 30 days by default.
- **`mageos_ai_usage_daily`** holds a daily total per (service, model, consumer, store)
  combination, built once a day from whatever is about to fall out of the raw table. It is pruned
  down to the much longer **Daily Usage Retention** window — 730 days (roughly two years) by
  default.

The dashboard's totals, breakdowns and graphs draw on both tables together, merging whichever
period they cover; the grid only ever reads the raw table.

### Grid vs. dashboard: what "30 days" limits

**The grid only shows calls still inside the raw retention window.** Once a call's day has been
rolled up and its raw row deleted — by default, once it is more than 30 days old — it disappears
from the grid entirely. It has not been lost: it lives on as part of a daily total, visible on the
dashboard (whose period can go back a full year) and through the CLI report, just no longer as an
individual row you can filter or sort on the grid. If a number on the dashboard does not have a
matching row you can find in the grid, this is why — the admin page states the same thing in a
notice above the grid.

### Bucketing and timezones

Days are bucketed in the **store timezone**, computed in PHP from the store's configured
timezone — never in the database with `DATE()` or `CONVERT_TZ()`, which depend on timezone tables
a customer's MySQL is not guaranteed to have loaded. This is why a call made late in the evening
lands on the calendar date a merchant looking at the clock on their own wall would expect, rather
than on whatever date UTC happens to be at that same instant. It is also why the boundary is
correct across a daylight-saving transition: the day it falls on is still exactly one bucket,
computed from real calendar arithmetic in the store's actual timezone rather than a fixed
UTC offset.

### Turning tracking off

The **Enable Usage Tracking** toggle stops recording immediately: from the next call onward, no
row is written at all, and the cleanup cron exits without touching either table. It does not
delete anything already recorded — history collected while tracking was on stays exactly where it
is, visible on the dashboard and in the grid (for whatever is still inside the raw retention
window), until the normal retention rules age it out on their own schedule.

### How recording works, mechanically

Recording is **synchronous**: one small insert into `mageos_ai_usage_log`, right after a call
completes, in the same request that made it. There is no queue and nothing happens in the
background. A failure to write that row is logged and otherwise ignored — it can never turn an AI
call that already succeeded into one that fails, and it can never delay the response the call
already produced.

## Model attribution

The model recorded against a call is **the model the call actually ran against**, not
necessarily the one configured on the service row. A consumer can override the model for a single
call (`AiClientInterface::OPTION_MODEL`) — a cheap model for bulk summarisation on the same
configured row a chat feature uses a stronger one on, for example — and the recorded row reflects
that override, not the row's own default. This is deliberate: token counts and eventual cost are a
property of the model that actually processed the call, and recording the configured default
regardless would mis-attribute exactly the calls that option exists to redirect.
