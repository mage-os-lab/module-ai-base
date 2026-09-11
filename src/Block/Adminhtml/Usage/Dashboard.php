<?php

declare(strict_types=1);

namespace MageOS\AiBase\Block\Adminhtml\Usage;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MageOS\AiBase\Api\Data\Granularity;
use MageOS\AiBase\Api\Data\Period;
use MageOS\AiBase\Api\Data\UsageBreakdownInterface;
use MageOS\AiBase\Api\Data\UsageTotalsInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\UsageStatsInterface;
use MageOS\AiBase\Model\Usage\Graph\DataPoint;
use MageOS\AiBase\Model\Usage\Graph\SvgRenderer;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Model\Usage\UsageConfig;

/**
 * Prepares the totals, breakdowns and graphs the dashboard template renders, and nothing else: no
 * query and no aggregation live here, both stay in {@see UsageStatsInterface} and its
 * implementation, per this task's brief. Acts as a ViewModel in spirit even though it is wired as
 * a plain layout block, the same as {@see \MageOS\AiBase\Block\Adminhtml\Configuration\Services}.
 *
 * The period an administrator is looking at is carried in the request rather than in block state,
 * so a bookmarked or shared URL reproduces the same view. It is validated against
 * {@see self::ALLOWED_PERIODS} rather than trusted, since a request parameter is attacker
 * controlled and {@see Period} has no named constructor that accepts an arbitrary string.
 */
class Dashboard extends Template
{
    /**
     * @var string
     */
    protected $_template = 'MageOS_AiBase::usage/dashboard.phtml';

    /**
     * Period selector value for the current local calendar day.
     */
    public const PERIOD_TODAY = 'today';

    /**
     * Period selector value for the current local calendar month; the default when no period was
     * requested, since a month is the window most merchants think of spend in.
     */
    public const PERIOD_THIS_MONTH = 'this_month';

    /**
     * Period selector value for the current local calendar year.
     */
    public const PERIOD_THIS_YEAR = 'this_year';

    /**
     * The only period values the request parameter may resolve to.
     *
     * @var string[]
     */
    private const ALLOWED_PERIODS = [self::PERIOD_TODAY, self::PERIOD_THIS_MONTH, self::PERIOD_THIS_YEAR];

    /**
     * Fallback for an absent or invalid period request parameter.
     */
    private const DEFAULT_PERIOD = self::PERIOD_THIS_MONTH;

    /**
     * Name of the GET parameter carrying the selected period.
     */
    private const REQUEST_PARAM_PERIOD = 'period';

    /**
     * Consumers given a line of their own before the rest fold into "Other".
     *
     * Five is the palette's slot count and the soft cap on series a reader can hold apart at a
     * glance; a sixth line would need a hue the palette does not have.
     */
    private const TREND_SERIES_LIMIT = 5;

    /**
     * Trend selector value: one line per consumer.
     */
    public const TREND_BY_CONSUMER = 'consumer';

    /**
     * Trend selector value: one line per configured service row.
     */
    public const TREND_BY_SERVICE = 'service';

    /**
     * The only trend values the request parameter may resolve to.
     *
     * @var string[]
     */
    private const ALLOWED_TRENDS = [self::TREND_BY_CONSUMER, self::TREND_BY_SERVICE];

    /**
     * Fallback for an absent or invalid trend request parameter. Consumer rather than service
     * because "which feature is spending this" is the question the page is usually opened with;
     * a store on one provider has a by-service chart with a single line.
     */
    private const DEFAULT_TREND = self::TREND_BY_CONSUMER;

    /**
     * Name of the GET parameter carrying the selected trend.
     */
    private const REQUEST_PARAM_TREND = 'series';

    /**
     * Name of the GET parameter carrying the selected store.
     */
    private const REQUEST_PARAM_STORE = 'store';

    /**
     * Value of {@see REQUEST_PARAM_STORE} meaning every store rather than one of them.
     *
     * A word rather than an empty string so the parameter reads the same in a shared link as it
     * does in the selector, and so "all" can never be confused with store id 0, which is the admin
     * store and a real scope a call can be recorded in.
     */
    private const STORE_ALL = 'all';

    /**
     * The store id every call made outside a storefront is recorded against.
     */
    private const STORE_ADMIN = 0;

    /**
     * Memoized result of {@see currentPeriod()}, computed at most once per request: every
     * getter below that needs "the selected period" resolves it lazily and reuses the same
     * instant, so two calls within one render never straddle a real clock tick into different
     * calendar windows.
     *
     * @var Period|null
     */
    private ?Period $currentPeriodCache = null;

    /**
     * Figures already fetched during this render.
     *
     * The template asks for the same things more than once by design — the headline reads the
     * totals, the empty-state check reads them, the change-since-last-period reads them — and each
     * is two round trips. Memoised here rather than in {@see UsageStatsInterface}'s implementation,
     * which is a shared instance: a cache there outlives the request and would hand stale figures
     * to anything long-running that records usage and then reads it back, which the integration
     * suite caught it doing between two of its own tests. A block is one render, so this cannot.
     *
     * @var UsageTotalsInterface|null
     */
    private ?UsageTotalsInterface $totalsCache = null;

    /**
     * @var UsageBreakdownInterface[]|null
     */
    private ?array $consumerBreakdownCache = null;

    /**
     * @var UsageBreakdownInterface[]|null
     */
    private ?array $serviceBreakdownCache = null;

    /**
     * @param Context $context
     * @param UsageStatsInterface $usageStats Read-only source of every total, breakdown and
     *        time-series row this block exposes.
     * @param UsageConfig $usageConfig Tells the template whether tracking is switched on at all.
     * @param SvgRenderer $svgRenderer Turns a breakdown into inline chart markup; see its own
     *        docblock for why the result is not escaped again by the template.
     * @param TimezoneInterface $timezone Store timezone {@see Period}'s named constructors resolve
     *        local calendar boundaries against.
     * @param AiServiceSelectorInterface $serviceSelector Configured rows, for turning the stored
     *        `service_id` of a breakdown into the provider name an administrator recognises.
     * @param ServiceRegistry $serviceRegistry Registered backends, source of that provider name.
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager Stores the page can be scoped to.
     * @param array<string,mixed> $data
     * @param \DateTimeImmutable|null $now Deterministic clock for tests, the same convention
     *        {@see Period::today()} documents; production callers leave this null and get the real
     *        current instant.
     */
    public function __construct(
        Context $context,
        private readonly UsageStatsInterface $usageStats,
        private readonly UsageConfig $usageConfig,
        private readonly SvgRenderer $svgRenderer,
        private readonly TimezoneInterface $timezone,
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        array $data = [],
        private readonly ?\DateTimeImmutable $now = null,
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The validated period code the page is currently showing.
     *
     * @return string One of {@see self::ALLOWED_PERIODS}.
     */
    public function getSelectedPeriodCode(): string
    {
        return $this->periodCode();
    }

    /**
     * The period selector's options, in display order.
     *
     * @return array<string,\Magento\Framework\Phrase>
     */
    public function getPeriodOptions(): array
    {
        return [
            self::PERIOD_TODAY => __('Today'),
            self::PERIOD_THIS_MONTH => __('This month'),
            self::PERIOD_THIS_YEAR => __('This year'),
        ];
    }

    /**
     * Whether the administrator has switched usage tracking off.
     *
     * The template says so explicitly rather than leaving an administrator looking at an empty
     * dashboard to guess why.
     *
     * @return bool
     */
    public function isTrackingDisabled(): bool
    {
        return !$this->usageConfig->isEnabled();
    }

    /**
     * Whether the selected period has no recorded usage at all.
     *
     * Read off the grand total's call count rather than the presence of either breakdown, since
     * an aggregate with zero calls is what "nothing happened" means regardless of which table it
     * would have come from.
     *
     * @return bool
     */
    public function hasNoUsageData(): bool
    {
        return $this->getTotals()->getCalls() === 0;
    }

    /**
     * The grand total for the selected period.
     *
     * @return UsageTotalsInterface
     */
    public function getTotals(): UsageTotalsInterface
    {
        return $this->totalsCache ??= $this->usageStats->getTotals($this->currentPeriod(), $this->storeId());
    }

    /**
     * The selected period broken down by consumer, already ordered by tokens descending by
     * {@see UsageStatsInterface::getByConsumer()}'s own contract.
     *
     * @return UsageBreakdownInterface[]
     */
    public function getConsumerBreakdown(): array
    {
        return $this->consumerBreakdownCache
            ??= $this->usageStats->getByConsumer($this->currentPeriod(), $this->storeId());
    }

    /**
     * The selected period broken down by service row, ordered the same way as
     * {@see getConsumerBreakdown()}.
     *
     * @return UsageBreakdownInterface[]
     */
    public function getServiceBreakdown(): array
    {
        return $this->serviceBreakdownCache
            ??= $this->usageStats->getByService($this->currentPeriod(), $this->storeId());
    }

    /**
     * Inline SVG bar chart of {@see getConsumerBreakdown()}.
     *
     * @return string Emit with `@noEscape`; see {@see SvgRenderer}'s docblock for why.
     */
    public function getConsumerGraph(): string
    {
        return $this->svgRenderer->renderBarChart(
            $this->toDataPoints($this->getConsumerBreakdown()),
            (string) __('Tokens by consumer')
        );
    }

    /**
     * Inline SVG bar chart of {@see getServiceBreakdown()}.
     *
     * @return string Emit with `@noEscape`; see {@see SvgRenderer}'s docblock for why.
     */
    public function getServiceGraph(): string
    {
        $serviceLabels = $this->serviceLabels();

        return $this->svgRenderer->renderBarChart(
            array_map(
                fn (UsageBreakdownInterface $row): DataPoint => new DataPoint(
                    $serviceLabels[$row->getGroupValue()] ?? $row->getGroupValue(),
                    (float) $row->getTotals()->getTotalTokens()
                ),
                $this->getServiceBreakdown()
            ),
            (string) __('Tokens by service')
        );
    }

    /**
     * Whether the selected period is long enough for a trend to say anything.
     *
     * "Today" is a single bucket, and a line through one point is a dot with a stroke: it answers
     * no question the headline figure has not already answered. The section is left out entirely
     * rather than rendered empty.
     *
     * @return bool
     */
    public function hasTrend(): bool
    {
        return $this->periodCode() !== self::PERIOD_TODAY;
    }

    /**
     * Inline SVG trend of the selected period.
     *
     * One point per day for a month, one per month for a year.
     *
     * The bar charts answer "who spent it"; this answers "is it growing", which the
     * period-over-period figure states as a single number and this states as a shape.
     *
     * @return string Emit with `@noEscape`; see {@see SvgRenderer}'s docblock for why.
     */
    public function getTrendGraph(): string
    {
        $granularity = $this->periodCode() === self::PERIOD_THIS_YEAR
            ? Granularity::Month
            : Granularity::Day;
        $byService = $this->trendCode() === self::TREND_BY_SERVICE;

        $grouped = $byService
            ? $this->usageStats->getTimeSeriesByService(
                $this->elapsedPeriod(),
                $granularity,
                self::TREND_SERIES_LIMIT,
                $this->storeId()
            )
            : $this->usageStats->getTimeSeriesByConsumer(
                $this->elapsedPeriod(),
                $granularity,
                self::TREND_SERIES_LIMIT,
                $this->storeId()
            );

        $serviceLabels = $byService ? $this->serviceLabels() : [];
        $series = [];
        foreach ($grouped as $groupValue => $buckets) {
            $label = $this->seriesLabel((string) $groupValue, $serviceLabels);
            $series[$label] = $this->toTrendDataPoints($buckets, $granularity);
        }

        return $this->svgRenderer->renderMultiTrendChart($series, (string) __('Tokens over time'));
    }

    /**
     * The validated trend the page is currently showing.
     *
     * @return string One of {@see self::ALLOWED_TRENDS}.
     */
    public function getSelectedTrendCode(): string
    {
        return $this->trendCode();
    }

    /**
     * The trend selector's options, in display order.
     *
     * @return array<string,\Magento\Framework\Phrase>
     */
    public function getTrendOptions(): array
    {
        return [
            self::TREND_BY_CONSUMER => __('By consumer'),
            self::TREND_BY_SERVICE => __('By service'),
        ];
    }

    /**
     * A link to the same view with a different trend, keeping the selected period.
     *
     * Built here rather than in the template so the two request parameters are never combined by
     * hand in markup, where dropping one silently resets the period.
     *
     * @param string $trendCode
     * @return string
     */
    public function getTrendUrl(string $trendCode): string
    {
        return $this->viewUrl($this->periodCode(), $trendCode, $this->getSelectedStoreCode());
    }

    /**
     * A link to the same view over a different period, keeping the selected trend.
     *
     * @param string $periodCode
     * @return string
     */
    public function getPeriodUrl(string $periodCode): string
    {
        return $this->viewUrl($periodCode, $this->trendCode(), $this->getSelectedStoreCode());
    }

    /**
     * A link carrying the whole view, so changing one selector never resets another.
     *
     * @param string $periodCode
     * @param string $trendCode
     * @param string $storeCode
     * @return string
     */
    private function viewUrl(string $periodCode, string $trendCode, string $storeCode): string
    {
        return sprintf(
            '?%s=%s&%s=%s&%s=%s',
            self::REQUEST_PARAM_PERIOD,
            $periodCode,
            self::REQUEST_PARAM_TREND,
            $trendCode,
            self::REQUEST_PARAM_STORE,
            $storeCode
        );
    }

    /**
     * The store selector's options: every store, then each one by name.
     *
     * Read from {@see StoreManagerInterface} rather than from the recorded rows, so a store that
     * has not been used yet is still selectable and one that was deleted stops being offered while
     * its history stays in the table.
     *
     * PHP coerces a numeric string key to an int, so a store id lands as an int key however it is
     * cast on the way in; the template stringifies each key when it builds a link.
     *
     * @return array<array-key,string>
     */
    public function getStoreOptions(): array
    {
        /** @var array<array-key,string> $options */
        $options = [self::STORE_ALL => (string) __('All stores')];
        // `true` keeps the admin store in the list. Every call made outside a storefront — an
        // admin controller, cron, the CLI — is recorded against store 0
        // ({@see \MageOS\AiBase\Model\Client\RecordingAiClient::resolveStoreId()}), which on
        // most installs is the bulk of this module's traffic; a selector that quietly omitted it
        // would offer no way to look at exactly the usage an administrator most wants to see.
        foreach ($this->storeManager->getStores(true) as $store) {
            $options[(string) $store->getId()] = $this->storeLabel($store);
        }

        return $options;
    }

    /**
     * The name a store goes by in the selector.
     *
     * The admin store is relabelled: "Admin" is what the row is called, but what it actually
     * holds here is everything that ran with no storefront in scope.
     *
     * @param \Magento\Store\Api\Data\StoreInterface $store
     * @return string
     */
    private function storeLabel(\Magento\Store\Api\Data\StoreInterface $store): string
    {
        return (int) $store->getId() === self::STORE_ADMIN
            ? (string) __('Admin, cron and CLI')
            : (string) $store->getName();
    }

    /**
     * Whether the scope selector is worth drawing at all.
     *
     * It needs at least two real scopes behind the "All stores" entry to be a choice rather than
     * a label. An install always has the admin scope plus one storefront, so in practice this is
     * true; it stays a question rather than an assumption because {@see getStoreOptions()} is
     * what decides which scopes exist, and a template should not be re-deriving that from a count.
     *
     * @return bool
     */
    public function hasStoreChoice(): bool
    {
        return count($this->getStoreOptions()) > 2;
    }

    /**
     * A link to the same view scoped to a different store, keeping the period and the trend.
     *
     * @param string $storeCode
     * @return string
     */
    public function getStoreUrl(string $storeCode): string
    {
        return $this->viewUrl($this->periodCode(), $this->trendCode(), $storeCode);
    }

    /**
     * The selected store as the selector spells it, for marking the current option.
     *
     * @return string
     */
    public function getSelectedStoreCode(): string
    {
        $storeId = $this->storeId();

        return $storeId === null ? self::STORE_ALL : (string) $storeId;
    }

    /**
     * The store id the request asked for, validated against the stores that exist.
     *
     * An id naming no store falls back to every store rather than to none: a stale bookmark should
     * show a total that is too broad, which a reader can see, rather than an empty page that looks
     * like the feature is broken.
     *
     * @return int|null
     */
    private function storeId(): ?int
    {
        $requested = $this->getRequest()->getParam(self::REQUEST_PARAM_STORE, self::STORE_ALL);
        if (!is_string($requested) || $requested === self::STORE_ALL || !ctype_digit($requested)) {
            return null;
        }

        return array_key_exists($requested, $this->getStoreOptions()) ? (int) $requested : null;
    }

    /**
     * The validated trend code, from the request parameter, falling back to
     * {@see self::DEFAULT_TREND}.
     *
     * @return string
     */
    private function trendCode(): string
    {
        $requested = $this->getRequest()->getParam(self::REQUEST_PARAM_TREND, self::DEFAULT_TREND);
        if (!is_string($requested) || !in_array($requested, self::ALLOWED_TRENDS, true)) {
            return self::DEFAULT_TREND;
        }

        return $requested;
    }

    /**
     * A series' name as the legend shows it.
     *
     * The stats layer keys its folded tail with a machine constant; the legend needs a word. A
     * by-service series is keyed by the row's opaque id, which the legend resolves to the provider
     * name the same way the breakdown beneath it does.
     *
     * @param string $groupValue
     * @param array<string,string> $serviceLabels
     * @return string
     */
    private function seriesLabel(string $groupValue, array $serviceLabels): string
    {
        if ($groupValue === UsageStatsInterface::SERIES_OTHER) {
            return SvgRenderer::OTHER_SERIES_LABEL;
        }

        return $serviceLabels[$groupValue] ?? $groupValue;
    }

    /**
     * Heading for the trend, naming the bucket the reader is looking at.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getTrendLabel(): \Magento\Framework\Phrase
    {
        return $this->periodCode() === self::PERIOD_THIS_YEAR ? __('Per month') : __('Per day');
    }

    /**
     * The selected period's total tokens, thousands grouped.
     *
     * Formatted here rather than in the template because the template holds no logic, and left
     * exact rather than abbreviated: this is the one figure on the page an administrator may want
     * to read off precisely, and the graphs beside it already carry the abbreviated view.
     *
     * @return string
     */
    public function getFormattedTotalTokens(): string
    {
        return number_format((float) $this->getTotals()->getTotalTokens(), 0);
    }

    /**
     * Provider name per configured row id, for turning a by-service breakdown's opaque
     * `service_id` into something an administrator recognises.
     *
     * The same mapping {@see \MageOS\AiBase\Model\Usage\Source\ServiceRow} applies to the
     * grid's filter, and for the same reason: `service_id` is a JSON object key the admin form
     * generated, which names nothing. A row whose provider is no longer registered, or whose id no
     * longer resolves to a configured row at all, keeps its raw id at the call site rather than
     * disappearing from the chart.
     *
     * @return array<string,string>
     */
    private function serviceLabels(): array
    {
        $labels = [];
        foreach ($this->serviceSelector->getAll() as $service) {
            $labels[$service->getId()] = $this->serviceLabel($service);
        }

        return $labels;
    }

    /**
     * Human provider name for one configured row, falling back to its raw service code.
     *
     * @param AiServiceInterface $service
     * @return string
     */
    private function serviceLabel(AiServiceInterface $service): string
    {
        return $this->serviceRegistry->get($service->getCode())?->getName() ?? $service->getCode();
    }

    /**
     * The selected period's total tokens against the same elapsed span of the immediately
     * preceding period of the same kind (yesterday for "today", last month for "this month", last
     * year for "this year"), expressed as a percentage change.
     *
     * Like-for-like on purpose: see {@see previousPeriod()} for why the previous window is
     * truncated to the current one's elapsed length instead of being taken whole.
     *
     * Null, not a computed figure, when the previous period had no usage at all: a percentage
     * against zero is either infinite or meaningless, and showing one anyway would read as a real
     * number to an administrator who has no way to tell it apart from one that means something.
     *
     * @return float|null Rounded to one decimal place.
     */
    public function getPeriodOverPeriodChangePercent(): ?float
    {
        $previousTotal = $this->usageStats->getTotals($this->previousPeriod(), $this->storeId())->getTotalTokens();
        if ($previousTotal === 0) {
            return null;
        }

        $currentTotal = $this->getTotals()->getTotalTokens();

        return round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1);
    }

    /**
     * The validated period code, resolved from the request parameter and falling back to
     * {@see self::DEFAULT_PERIOD} for anything absent or not in {@see self::ALLOWED_PERIODS}.
     *
     * @return string
     */
    private function periodCode(): string
    {
        $requestedPeriod = $this->getRequest()->getParam(self::REQUEST_PARAM_PERIOD, self::DEFAULT_PERIOD);
        if (!is_string($requestedPeriod) || !in_array($requestedPeriod, self::ALLOWED_PERIODS, true)) {
            return self::DEFAULT_PERIOD;
        }

        return $requestedPeriod;
    }

    /**
     * The window the page is currently showing.
     *
     * @return Period
     */
    private function currentPeriod(): Period
    {
        return $this->currentPeriodCache ??= $this->buildPeriod($this->periodCode(), $this->now);
    }

    /**
     * The window immediately preceding {@see currentPeriod()}, of the same kind.
     *
     * Built by asking {@see Period}'s own named constructor to resolve "today"/"this
     * month"/"this year" around a reference instant one second before the current period starts,
     * rather than subtracting a fixed duration: that is what keeps a month's previous period the
     * calendar month before it regardless of the two months' differing lengths, and keeps a
     * year's previous period correct across a leap year.
     *
     * @return Period
     */
    private function previousPeriod(): Period
    {
        $current = $this->currentPeriod();
        $previousFull = $this->buildPeriod($this->periodCode(), $current->getStart()->modify('-1 second'));

        // Truncated to the same elapsed span the current period has actually had, rather than
        // taken whole. On the fourth of a month, "this month" is four days old and the calendar
        // month before it is thirty-one: comparing the two reports a collapse in spend every time
        // a period turns over, which is a statement about the calendar rather than about usage.
        // Clamped to the previous period's own end so a shorter previous period (February, or a
        // partial first period after tracking was switched on) is never over-extended.
        $comparableEnd = $previousFull->getStart()
            ->modify(sprintf('+%d seconds', $this->elapsedSecondsInCurrentPeriod()));
        if ($comparableEnd > $previousFull->getEnd()) {
            $comparableEnd = $previousFull->getEnd();
        }

        return Period::between($previousFull->getStart(), $comparableEnd);
    }

    /**
     * The selected period, cut off at the current instant.
     *
     * A period runs to the end of its calendar window, which for the current month or year is
     * mostly in the future. Bucketing that whole window puts a run of empty buckets after the last
     * real one, and a line drawn through them falls to zero and stays there — which reads as usage
     * having stopped rather than as days that have not happened yet. The totals are unaffected
     * either way (there is no future data to include), so only the series is clamped.
     *
     * @return Period
     */
    private function elapsedPeriod(): Period
    {
        $current = $this->currentPeriod();

        return Period::between(
            $current->getStart(),
            $current->getStart()->modify(sprintf('+%d seconds', $this->elapsedSecondsInCurrentPeriod()))
        );
    }

    /**
     * How far into the selected period the current instant is, in seconds.
     *
     * Bounded by the period's own end so a period entirely in the past (which the selector cannot
     * currently produce, but {@see buildPeriod()} could) reports its full length rather than a
     * span reaching to now.
     *
     * @return int
     */
    private function elapsedSecondsInCurrentPeriod(): int
    {
        $current = $this->currentPeriod();
        $now = $this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $boundedNow = $now < $current->getEnd() ? $now : $current->getEnd();

        return max(0, $boundedNow->getTimestamp() - $current->getStart()->getTimestamp());
    }

    /**
     * Resolves a period code to the {@see Period} it names, around a given reference instant.
     *
     * @param string $periodCode One of {@see self::ALLOWED_PERIODS}.
     * @param \DateTimeImmutable|null $referenceInstant
     * @return Period
     */
    private function buildPeriod(string $periodCode, ?\DateTimeImmutable $referenceInstant): Period
    {
        return match ($periodCode) {
            self::PERIOD_TODAY => Period::today($this->timezone, $referenceInstant),
            self::PERIOD_THIS_MONTH => Period::thisMonth($this->timezone, $referenceInstant),
            self::PERIOD_THIS_YEAR => Period::thisYear($this->timezone, $referenceInstant),
            default => throw new \LogicException(
                sprintf('"%s" is not one of the allowed period codes; periodCode() must reject it first.', $periodCode)
            ),
        };
    }

    /**
     * Turns a time series into data points labelled for a reader rather than for a database.
     *
     * {@see UsageStatsInterface::getTimeSeries()} labels each bucket `Y-m-d` or `Y-m`, which is
     * the right key for grouping and the wrong one for an axis. The renderer takes labels and
     * numbers and knows nothing about dates, so the reformatting belongs here.
     *
     * @param UsageBreakdownInterface[] $series
     * @param Granularity $granularity
     * @return DataPoint[]
     */
    private function toTrendDataPoints(array $series, Granularity $granularity): array
    {
        return array_map(
            fn (UsageBreakdownInterface $bucket): DataPoint => new DataPoint(
                $this->formatBucketLabel($bucket->getGroupValue(), $granularity),
                (float) $bucket->getTotals()->getTotalTokens()
            ),
            $series
        );
    }

    /**
     * A bucket key rendered for the axis: `2026-09-05` as `5 Sep`, `2026-09` as `Sep`.
     *
     * An unparseable key is passed through untouched rather than replaced with a guess — if the
     * series ever labels a bucket some other way, an odd axis label is a better failure than a
     * wrong date.
     *
     * @param string $bucketKey
     * @param Granularity $granularity
     * @return string
     */
    private function formatBucketLabel(string $bucketKey, Granularity $granularity): string
    {
        $format = $granularity === Granularity::Month ? 'Y-m' : 'Y-m-d';
        $bucket = \DateTimeImmutable::createFromFormat('!' . $format, $bucketKey);

        if ($bucket === false) {
            return $bucketKey;
        }

        return $granularity === Granularity::Month ? $bucket->format('M') : $bucket->format('j M');
    }

    /**
     * Turns a breakdown into the labelled values {@see SvgRenderer} draws.
     *
     * @param UsageBreakdownInterface[] $breakdown
     * @return DataPoint[]
     */
    private function toDataPoints(array $breakdown): array
    {
        return array_map(
            fn (UsageBreakdownInterface $row): DataPoint => new DataPoint(
                $row->getGroupValue(),
                (float) $row->getTotals()->getTotalTokens()
            ),
            $breakdown
        );
    }
}
