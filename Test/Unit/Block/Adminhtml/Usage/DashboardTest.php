<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Block\Adminhtml\Usage;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiBase\Api\Data\Granularity;
use MageOS\AiBase\Api\Data\Period;
use MageOS\AiBase\Api\Data\UsageBreakdownInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Block\Adminhtml\Usage\Dashboard;
use MageOS\AiBase\Model\AiService;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Model\Usage\Graph\BarChartRenderer;
use MageOS\AiBase\Model\Usage\Graph\ChartValues;
use MageOS\AiBase\Model\Usage\Graph\SeriesPalette;
use MageOS\AiBase\Model\Usage\Graph\SvgDocument;
use MageOS\AiBase\Model\Usage\Graph\SvgRenderer;
use MageOS\AiBase\Model\Usage\Graph\TrendRenderer;
use MageOS\AiBase\Model\Usage\UsageBreakdown;
use MageOS\AiBase\Model\Usage\UsageConfig;
use MageOS\AiBase\Model\Usage\UsageTotals;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Block\Adminhtml\Usage\Dashboard
 *
 * Exercises {@see Dashboard} against {@see FakeUsageStats}, an in-memory stand-in for
 * {@see \MageOS\AiBase\Api\UsageStatsInterface}, per this codebase's fakes-over-mocks convention.
 * `Magento\Backend\Block\Template::__construct()` resolves collaborators through
 * `ObjectManager::getInstance()`, unavailable here, so every test builds the block through
 * reflection the same way {@see \MageOS\AiBase\Test\Unit\Block\Adminhtml\Configuration\ServicesButtonsTest}
 * does, and sets only the properties the method under test reads.
 */
final class DashboardTest extends TestCase
{
    private const TEMPLATE_FILE = __DIR__ . '/../../../../../src/view/adminhtml/templates/usage/dashboard.phtml';

    private FakeUsageStats $usageStats;
    private UsageConfig&MockObject $usageConfig;
    private SvgRenderer $svgRenderer;
    private FakeServiceSelector $serviceSelector;
    private ServiceRegistry $serviceRegistry;
    private FakeStoreManager $storeManager;

    protected function setUp(): void
    {
        $this->usageStats = new FakeUsageStats();
        $this->usageConfig = $this->createMock(UsageConfig::class);
        $this->usageConfig->method('isEnabled')->willReturn(true);
        $document = new SvgDocument();
        $values = new ChartValues();
        $this->svgRenderer = new SvgRenderer(
            new BarChartRenderer($document, $values),
            new TrendRenderer($document, $values, new SeriesPalette())
        );
        $this->serviceSelector = new FakeServiceSelector();
        $this->serviceRegistry = new ServiceRegistry();
        $this->storeManager = new FakeStoreManager();
    }

    public function test_it_exposes_totals_for_the_selected_period(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));
        $block = $this->blockRequesting(Dashboard::PERIOD_TODAY, $now);
        $today = Period::today(new FakeTimezone('UTC'), $now);

        $this->usageStats->withTotalsForPeriod($today, $this->totals(42, 3));

        self::assertSame(42, $block->getTotals()->getTotalTokens());
        self::assertSame(3, $block->getTotals()->getCalls());
    }

    public function test_it_defaults_to_the_current_month_when_no_period_was_requested(): void
    {
        $block = $this->blockRequesting(null, null);

        self::assertSame(Dashboard::PERIOD_THIS_MONTH, $block->getSelectedPeriodCode());
    }

    public function test_it_rejects_a_period_that_is_not_one_of_the_allowed_values(): void
    {
        $block = $this->blockRequesting('not-a-real-period', null);

        self::assertSame(Dashboard::PERIOD_THIS_MONTH, $block->getSelectedPeriodCode());
    }

    public function test_it_exposes_the_consumer_breakdown_ordered_by_tokens_descending(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);
        $breakdown = [
            new UsageBreakdown('chat', $this->totals(150, 5)),
            new UsageBreakdown('docs_search', $this->totals(50, 2)),
        ];
        $this->usageStats->withConsumerBreakdown($breakdown);

        self::assertSame($breakdown, $block->getConsumerBreakdown());
    }

    public function test_it_exposes_the_service_row_breakdown_ordered_by_tokens_descending(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);
        $breakdown = [
            new UsageBreakdown('anthropic-1', $this->totals(200, 6)),
            new UsageBreakdown('openai-1', $this->totals(20, 1)),
        ];
        $this->usageStats->withServiceBreakdown($breakdown);

        self::assertSame($breakdown, $block->getServiceBreakdown());
    }

    public function test_it_exposes_a_rendered_graph_for_each_breakdown(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);
        $this->usageStats->withConsumerBreakdown([
            new UsageBreakdown('chat', $this->totals(150, 5)),
            new UsageBreakdown('docs_search', $this->totals(50, 2)),
        ]);
        $this->usageStats->withServiceBreakdown([
            new UsageBreakdown('anthropic-1', $this->totals(200, 6)),
        ]);

        self::assertSame(2, $this->countOccurrences('<rect', $block->getConsumerGraph()));
        self::assertSame(1, $this->countOccurrences('<rect', $block->getServiceGraph()));
    }

    public function test_it_labels_a_service_row_with_its_provider_name_in_the_graph(): void
    {
        $this->serviceSelector->withService(new AiService('_row_1', 'anthropic', []));
        $this->serviceRegistry = new ServiceRegistry([new FakeServiceConfiguration('anthropic', 'Anthropic')]);
        $this->usageStats->withServiceBreakdown([new UsageBreakdown('_row_1', $this->totals(200, 6))]);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        $graph = $block->getServiceGraph();

        self::assertStringContainsString('Anthropic', $graph);
        self::assertStringNotContainsString('>_row_1<', $graph);
    }

    public function test_it_falls_back_to_the_raw_service_id_when_no_row_carries_it(): void
    {
        $this->usageStats->withServiceBreakdown([new UsageBreakdown('_gone_row', $this->totals(200, 6))]);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        self::assertStringContainsString('_gone_row', $block->getServiceGraph());
    }

    public function test_it_groups_thousands_in_the_headline_total(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, $now);
        $this->usageStats->withTotalsForPeriod(
            Period::thisMonth(new FakeTimezone('UTC'), $now),
            $this->totals(1233615, 40)
        );

        self::assertSame('1,233,615', $block->getFormattedTotalTokens());
    }

    public function test_it_omits_the_trend_for_a_single_day_period(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_TODAY, null);

        self::assertFalse($block->hasTrend());
    }

    public function test_it_offers_a_trend_for_a_month(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        self::assertTrue($block->hasTrend());
        self::assertSame('Per day', (string) $block->getTrendLabel());
    }

    public function test_it_buckets_a_year_trend_per_month(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_YEAR, null);

        self::assertSame('Per month', (string) $block->getTrendLabel());
    }

    public function test_it_stops_the_trend_at_the_current_instant_rather_than_the_period_end(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, $now);

        $block->getTrendGraph();

        // June runs to the 30th; the series must be asked for only as far as the 15th, or the
        // graph draws a fortnight of empty buckets and falls to zero in the future.
        $requested = $this->usageStats->lastTimeSeriesPeriod();
        self::assertNotNull($requested);
        self::assertSame(
            $now->getTimestamp(),
            $requested->getEnd()->getTimestamp(),
            'The trend window must end now, not at the end of the calendar month.'
        );
    }

    public function test_it_labels_daily_trend_buckets_for_a_reader(): void
    {
        $this->usageStats->withTimeSeries([
            new UsageBreakdown('2026-09-01', $this->totals(10, 1)),
            new UsageBreakdown('2026-09-05', $this->totals(20, 2)),
        ]);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        $graph = $block->getTrendGraph();

        self::assertStringContainsString('1 Sep', $graph);
        self::assertStringContainsString('5 Sep', $graph);
        self::assertStringNotContainsString('2026-09-01', $graph);
    }

    public function test_it_labels_monthly_trend_buckets_by_month_name(): void
    {
        $this->usageStats->withTimeSeries([new UsageBreakdown('2026-03', $this->totals(10, 1))]);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_YEAR, null);

        self::assertStringContainsString('Mar', $block->getTrendGraph());
    }

    public function test_it_passes_an_unparseable_bucket_key_through_untouched(): void
    {
        $this->usageStats->withTimeSeries([new UsageBreakdown('not-a-date', $this->totals(10, 1))]);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        self::assertStringContainsString('not-a-date', $block->getTrendGraph());
    }

    public function test_it_draws_one_trend_line_per_consumer(): void
    {
        $this->usageStats->withConsumerSeries([
            'MaggyAssistant_Base' => [new UsageBreakdown('2026-09-01', $this->totals(100, 2))],
            'MyVendor_Summaries' => [new UsageBreakdown('2026-09-01', $this->totals(40, 1))],
        ]);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        $graph = $block->getTrendGraph();

        self::assertSame(2, substr_count($graph, 'class="mageos-ai-usage-graph-trend"'));
        self::assertStringContainsString('MaggyAssistant_Base', $graph);
        self::assertStringContainsString('MyVendor_Summaries', $graph);
    }

    public function test_it_shows_a_legend_for_the_series_it_drew(): void
    {
        $this->usageStats->withConsumerSeries([
            'A' => [new UsageBreakdown('2026-09-01', $this->totals(100, 2))],
            'B' => [new UsageBreakdown('2026-09-01', $this->totals(40, 1))],
        ]);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        self::assertSame(2, substr_count($block->getTrendGraph(), 'mageos-ai-usage-graph-legend-entry'));
    }

    public function test_it_asks_for_five_named_series_before_the_fold(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        $block->getTrendGraph();

        self::assertSame(5, $this->usageStats->lastSeriesLimit());
    }

    public function test_it_names_the_folded_tail_series_other(): void
    {
        $this->usageStats->withConsumerSeries([
            'A' => [new UsageBreakdown('2026-09-01', $this->totals(100, 2))],
            \MageOS\AiBase\Api\UsageStatsInterface::SERIES_OTHER => [
                new UsageBreakdown('2026-09-01', $this->totals(9, 1)),
            ],
        ]);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        $graph = $block->getTrendGraph();

        self::assertStringContainsString('>Other<', $graph);
        self::assertStringNotContainsString('>other<', $graph);
    }

    public function test_it_defaults_the_trend_to_consumers(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        self::assertSame(Dashboard::TREND_BY_CONSUMER, $block->getSelectedTrendCode());

        $block->getTrendGraph();
        self::assertSame('consumer', $this->usageStats->lastSeriesGrouping());
    }

    public function test_it_switches_the_trend_to_services_when_asked(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null, Dashboard::TREND_BY_SERVICE);

        $block->getTrendGraph();

        self::assertSame(Dashboard::TREND_BY_SERVICE, $block->getSelectedTrendCode());
        self::assertSame('service', $this->usageStats->lastSeriesGrouping());
    }

    public function test_it_rejects_a_trend_that_is_not_one_of_the_allowed_values(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null, 'not-a-real-trend');

        self::assertSame(Dashboard::TREND_BY_CONSUMER, $block->getSelectedTrendCode());
    }

    public function test_it_names_service_series_by_their_provider_in_the_legend(): void
    {
        $this->serviceSelector->withService(new AiService('_row_1', 'anthropic', []));
        $this->serviceRegistry = new ServiceRegistry([new FakeServiceConfiguration('anthropic', 'Anthropic')]);
        $this->usageStats->withServiceSeries([
            '_row_1' => [new UsageBreakdown('2026-09-01', $this->totals(100, 2))],
        ]);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null, Dashboard::TREND_BY_SERVICE);

        $graph = $block->getTrendGraph();

        self::assertStringContainsString('Anthropic', $graph);
        self::assertStringNotContainsString('>_row_1<', $graph);
    }

    public function test_it_keeps_the_trend_when_linking_to_another_period(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null, Dashboard::TREND_BY_SERVICE);

        // Dropping either parameter silently resets that half of the view.
        self::assertSame('?period=this_year&series=service&store=all', $block->getPeriodUrl(Dashboard::PERIOD_THIS_YEAR));
    }

    public function test_it_keeps_the_period_when_linking_to_another_trend(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_YEAR, null, Dashboard::TREND_BY_CONSUMER);

        self::assertSame('?period=this_year&series=service&store=all', $block->getTrendUrl(Dashboard::TREND_BY_SERVICE));
    }

    public function test_it_reports_the_change_against_the_equivalent_previous_period(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, $now);
        $currentMonth = Period::thisMonth(new FakeTimezone('UTC'), $now);
        $previousMonth = Period::thisMonth(new FakeTimezone('UTC'), $currentMonth->getStart()->modify('-1 second'));
        // The previous window is truncated to the elapsed span of the current one — half of June
        // compares against the same half of May, not against the whole of it.
        $elapsedSeconds = $now->getTimestamp() - $currentMonth->getStart()->getTimestamp();
        $comparablePreviousMonth = Period::between(
            $previousMonth->getStart(),
            $previousMonth->getStart()->modify(sprintf('+%d seconds', $elapsedSeconds))
        );

        $this->usageStats->withTotalsForPeriod($currentMonth, $this->totals(120, 4));
        $this->usageStats->withTotalsForPeriod($comparablePreviousMonth, $this->totals(100, 4));

        self::assertSame(20.0, $block->getPeriodOverPeriodChangePercent());
    }

    public function test_it_reports_no_change_when_the_previous_period_had_no_usage(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, $now);
        $currentMonth = Period::thisMonth(new FakeTimezone('UTC'), $now);

        $this->usageStats->withTotalsForPeriod($currentMonth, $this->totals(120, 4));

        self::assertNull($block->getPeriodOverPeriodChangePercent());
    }

    public function test_it_reports_that_no_usage_has_been_recorded_when_both_tables_are_empty(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        self::assertTrue($block->hasNoUsageData());
    }

    public function test_it_reports_that_tracking_is_disabled_when_the_config_toggle_is_off(): void
    {
        $this->usageConfig = $this->createMock(UsageConfig::class);
        $this->usageConfig->method('isEnabled')->willReturn(false);
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);

        self::assertTrue($block->isTrackingDisabled());
    }

    public function test_it_escapes_a_consumer_name_containing_html_when_rendering_the_template(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);
        $this->usageStats->withTotalsForPeriod(
            $this->currentThisMonth(null),
            $this->totals(10, 1)
        );
        $this->usageStats->withConsumerBreakdown([
            new UsageBreakdown('<script>alert(1)</script>', $this->totals(10, 1)),
        ]);

        $html = $this->render($block);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_it_renders_the_graph_markup_with_its_geometry_attributes_intact(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);
        $this->usageStats->withTotalsForPeriod(
            $this->currentThisMonth(null),
            $this->totals(10, 1)
        );
        $this->usageStats->withConsumerBreakdown([
            new UsageBreakdown('chat', $this->totals(10, 1)),
        ]);

        $html = $this->render($block);

        // The geometry has to survive into the page: Escaper::escapeHtml()'s attribute
        // allow-list strips exactly these, so their presence is what proves the SVG reached the
        // template unescaped rather than as an empty box.
        self::assertStringContainsString('viewBox="0 0', $html);
        self::assertStringContainsString('<path class="mageos-ai-usage-graph-bar"', $html);
        self::assertMatchesRegularExpression('/<path[^>]+ d="M[\d. ]/', $html);
    }

    public function test_it_offers_the_admin_store_where_cron_and_cli_usage_lands(): void
    {
        // Every call made outside a storefront is recorded against store 0, which on most
        // installs is the bulk of this module's traffic, so it has to be selectable.
        $this->storeManager->withStore(0, 'Admin')->withStore(1, 'Default Store View');
        $block = $this->blockRequesting(null, null);

        self::assertSame(
            ['all' => 'All stores', 0 => 'Admin, cron and CLI', 1 => 'Default Store View'],
            $block->getStoreOptions()
        );
    }

    public function test_it_scopes_the_page_to_the_admin_store_when_asked(): void
    {
        $this->storeManager->withStore(0, 'Admin')->withStore(1, 'Default Store View');
        $block = $this->blockRequesting(null, null, null, '0');

        self::assertSame('0', $block->getSelectedStoreCode());
    }

    public function test_it_offers_every_store_alongside_an_all_stores_option(): void
    {
        $this->storeManager->withStore(1, 'Default Store View')->withStore(2, 'Dutch Store View');
        $block = $this->blockRequesting(null, null);

        self::assertSame(
            ['all' => 'All stores', 1 => 'Default Store View', 2 => 'Dutch Store View'],
            $block->getStoreOptions()
        );
    }

    public function test_it_scopes_the_page_to_the_requested_store(): void
    {
        $this->storeManager->withStore(1, 'Default Store View')->withStore(2, 'Dutch Store View');
        $block = $this->blockRequesting(null, null, null, '2');

        self::assertSame('2', $block->getSelectedStoreCode());
    }

    public function test_it_covers_every_store_when_no_store_was_requested(): void
    {
        $this->storeManager->withStore(1, 'Default Store View');
        $block = $this->blockRequesting(null, null);

        self::assertSame('all', $block->getSelectedStoreCode());
    }

    public function test_it_falls_back_to_every_store_when_the_requested_store_does_not_exist(): void
    {
        // A stale bookmark should widen the report, which a reader can see, rather than empty it
        // out into something that looks like the feature is broken.
        $this->storeManager->withStore(1, 'Default Store View');
        $block = $this->blockRequesting(null, null, null, '99');

        self::assertSame('all', $block->getSelectedStoreCode());
    }

    public function test_it_keeps_the_period_and_the_trend_when_linking_to_another_store(): void
    {
        $this->storeManager->withStore(1, 'Default Store View');
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_YEAR, null, Dashboard::TREND_BY_SERVICE);

        self::assertSame('?period=this_year&series=service&store=1', $block->getStoreUrl('1'));
    }

    public function test_it_keeps_the_store_when_linking_to_another_period(): void
    {
        $this->storeManager->withStore(1, 'Default Store View');
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null, null, '1');

        self::assertSame(
            '?period=this_year&series=consumer&store=1',
            $block->getPeriodUrl(Dashboard::PERIOD_THIS_YEAR)
        );
    }

    public function test_it_reads_totals_for_the_selected_store_only(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));
        $this->storeManager->withStore(1, 'Default Store View')->withStore(2, 'Dutch Store View');
        $block = $this->blockRequesting(Dashboard::PERIOD_TODAY, $now, null, '2');
        $today = Period::today(new FakeTimezone('UTC'), $now);

        $this->usageStats->withTotalsForPeriod($today, $this->totals(42, 3), 2);

        self::assertSame(42, $block->getTotals()->getTotalTokens());
    }

    public function test_it_shows_failed_calls_in_the_dashboard_totals(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);
        $this->usageStats->withTotalsForPeriod(
            $this->currentThisMonth(null),
            new UsageTotals(10, 100, 50, 150, null, null, null, 3)
        );

        $html = $this->render($block);

        self::assertSame(3, $block->getFailedCalls());
        self::assertStringContainsString((string) __('Failed calls'), $html);
        self::assertStringContainsString('3', $html);
    }

    public function test_it_shows_cache_read_and_write_in_the_dashboard_totals(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);
        $this->usageStats->withTotalsForPeriod(
            $this->currentThisMonth(null),
            new UsageTotals(10, 1000, 500, 1500, 200, null, 75, 0)
        );

        $html = $this->render($block);

        self::assertSame('200', $block->getFormattedCacheReadTokens());
        self::assertSame('75', $block->getFormattedCacheWriteTokens());
        self::assertStringContainsString((string) __('Cache read tokens'), $html);
        self::assertStringContainsString((string) __('Cache write tokens'), $html);
        self::assertStringContainsString('200', $html);
        self::assertStringContainsString('75', $html);
    }

    public function test_it_shows_not_reported_for_null_cache_totals(): void
    {
        $block = $this->blockRequesting(Dashboard::PERIOD_THIS_MONTH, null);
        $this->usageStats->withTotalsForPeriod(
            $this->currentThisMonth(null),
            new UsageTotals(10, 1000, 500, 1500, null, null, null, 0)
        );

        $html = $this->render($block);

        self::assertSame((string) __('Not reported'), $block->getFormattedCacheReadTokens());
        self::assertSame((string) __('Not reported'), $block->getFormattedCacheWriteTokens());
        self::assertSame(2, substr_count($html, (string) __('Not reported')));
    }

    private function totals(int $totalTokens, int $calls): UsageTotals
    {
        return new UsageTotals($calls, $totalTokens, 0, $totalTokens, null, null);
    }

    private function currentThisMonth(?\DateTimeImmutable $now): Period
    {
        return Period::thisMonth(new FakeTimezone('UTC'), $now);
    }

    private function blockRequesting(
        ?string $periodParam,
        ?\DateTimeImmutable $now,
        ?string $trendParam = null,
        ?string $storeParam = null
    ): Dashboard {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) use ($periodParam, $trendParam, $storeParam) {
                if ($key === 'period' && $periodParam !== null) {
                    return $periodParam;
                }
                if ($key === 'series' && $trendParam !== null) {
                    return $trendParam;
                }
                if ($key === 'store' && $storeParam !== null) {
                    return $storeParam;
                }

                return $default;
            }
        );

        $reflection = new \ReflectionClass(Dashboard::class);
        $block = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('usageStats')->setValue($block, $this->usageStats);
        $reflection->getProperty('usageConfig')->setValue($block, $this->usageConfig);
        $reflection->getProperty('svgRenderer')->setValue($block, $this->svgRenderer);
        $reflection->getProperty('timezone')->setValue($block, new FakeTimezone('UTC'));
        $reflection->getProperty('serviceSelector')->setValue($block, $this->serviceSelector);
        $reflection->getProperty('serviceRegistry')->setValue($block, $this->serviceRegistry);
        $reflection->getProperty('storeManager')->setValue($block, $this->storeManager);
        $reflection->getProperty('now')->setValue($block, $now);
        $reflection->getProperty('_request')->setValue($block, $request);

        return $block;
    }

    private function render(Dashboard $block): string
    {
        $escaper = new Escaper();

        return (static function () use ($block, $escaper): string {
            ob_start();
            include DashboardTest::TEMPLATE_FILE;

            return (string) ob_get_clean();
        })();
    }

    private function countOccurrences(string $needle, string $haystack): int
    {
        return substr_count($haystack, $needle);
    }
}

/**
 * In-memory stand-in for {@see \MageOS\AiBase\Api\UsageStatsInterface}.
 *
 * Totals are keyed by the exact `[start, end)` window requested, since {@see Dashboard} asks for
 * both the current and the previous period through the same method; a fake that ignored the
 * period argument could not tell those two calls apart.
 */
final class FakeUsageStats implements \MageOS\AiBase\Api\UsageStatsInterface
{
    private ?Period $lastTimeSeriesPeriod = null;

    /** @var UsageBreakdownInterface[] */
    private array $timeSeries = [];

    /** @var array<string,UsageBreakdownInterface[]> */
    private array $consumerSeries = [];

    private ?int $lastSeriesLimit = null;

    private ?string $lastSeriesGrouping = null;

    /** @var array<string,UsageBreakdownInterface[]> */
    private array $serviceSeries = [];

    /**
     * @param array<string,UsageBreakdownInterface[]> $series
     */
    public function withServiceSeries(array $series): void
    {
        $this->serviceSeries = $series;
    }

    /**
     * Which grouping the block last asked the trend for.
     */
    public function lastSeriesGrouping(): ?string
    {
        return $this->lastSeriesGrouping;
    }

    /**
     * @param array<string,UsageBreakdownInterface[]> $series
     */
    public function withConsumerSeries(array $series): void
    {
        $this->consumerSeries = $series;
    }

    /**
     * How many named series the block asked for before the fold.
     */
    public function lastSeriesLimit(): ?int
    {
        return $this->lastSeriesLimit;
    }

    /**
     * @param UsageBreakdownInterface[] $series
     */
    public function withTimeSeries(array $series): void
    {
        $this->timeSeries = $series;
    }

    /**
     * The window {@see getTimeSeries()} was last asked for, so a test can assert on the range the
     * block requested rather than only on what came back.
     */
    public function lastTimeSeriesPeriod(): ?Period
    {
        return $this->lastTimeSeriesPeriod;
    }

    /**
     * @var array<string, UsageTotals>
     */
    private array $totalsByWindow = [];

    /**
     * @var UsageBreakdownInterface[]
     */
    private array $consumerBreakdown = [];

    /**
     * @var UsageBreakdownInterface[]
     */
    private array $serviceBreakdown = [];

    public function withTotalsForPeriod(Period $period, UsageTotals $totals, ?int $storeId = null): void
    {
        $this->totalsByWindow[$this->windowKey($period, $storeId)] = $totals;
    }

    /**
     * @param UsageBreakdownInterface[] $breakdown
     */
    public function withConsumerBreakdown(array $breakdown): void
    {
        $this->consumerBreakdown = $breakdown;
    }

    /**
     * @param UsageBreakdownInterface[] $breakdown
     */
    public function withServiceBreakdown(array $breakdown): void
    {
        $this->serviceBreakdown = $breakdown;
    }

    public function getTotals(Period $period, ?int $storeId = null): UsageTotals
    {
        return $this->totalsByWindow[$this->windowKey($period, $storeId)] ?? new UsageTotals(0, 0, 0, 0, null, null);
    }

    /**
     * @inheritdoc
     */
    public function getByConsumer(Period $period, ?int $storeId = null): array
    {
        return $this->consumerBreakdown;
    }

    /**
     * @inheritdoc
     */
    public function getByService(Period $period, ?int $storeId = null): array
    {
        return $this->serviceBreakdown;
    }

    /**
     * @inheritdoc
     */
    public function getTimeSeries(Period $period, Granularity $granularity, ?int $storeId = null): array
    {
        $this->lastTimeSeriesPeriod = $period;

        return $this->timeSeries;
    }

    /**
     * @return array<string,\MageOS\AiBase\Api\Data\UsageBreakdownInterface[]>
     */
    public function getTimeSeriesByConsumer(
        Period $period,
        Granularity $granularity,
        int $limit,
        ?int $storeId = null
    ): array
    {
        $this->lastTimeSeriesPeriod = $period;
        $this->lastSeriesLimit = $limit;
        $this->lastSeriesGrouping = 'consumer';

        return $this->consumerSeries !== [] ? $this->consumerSeries : ['total' => $this->timeSeries];
    }

    /**
     * @return array<string,\MageOS\AiBase\Api\Data\UsageBreakdownInterface[]>
     */
    public function getTimeSeriesByService(
        Period $period,
        Granularity $granularity,
        int $limit,
        ?int $storeId = null
    ): array
    {
        $this->lastTimeSeriesPeriod = $period;
        $this->lastSeriesLimit = $limit;
        $this->lastSeriesGrouping = 'service';

        return $this->serviceSeries !== [] ? $this->serviceSeries : ['total' => $this->timeSeries];
    }

    private function windowKey(Period $period, ?int $storeId = null): string
    {
        return $period->getStart()->format('c')
            . '|' . $period->getEnd()->format('c')
            . '|' . ($storeId ?? 'all');
    }
}

/**
 * In-memory stand-in for {@see TimezoneInterface} that only implements
 * {@see getConfigTimezone()}, the one method {@see Period} calls, matching the convention
 * already established by {@see \MageOS\AiBase\Test\Unit\Model\Usage\UsageMaintenanceTest}'s and
 * {@see \MageOS\AiBase\Test\Unit\Api\Data\PeriodTest}'s own fakes of the same interface.
 */
final class FakeTimezone implements TimezoneInterface
{
    public function __construct(private readonly string $timezoneName)
    {
    }

    public function getConfigTimezone($scopeType = null, $scopeCode = null)
    {
        return $this->timezoneName;
    }

    public function getDefaultTimezonePath()
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function getDefaultTimezone()
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function getDateFormat($type = \IntlDateFormatter::SHORT)
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function getDateFormatWithLongYear()
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function getTimeFormat($type = null)
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function getDateTimeFormat($type)
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true)
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function scopeDate($scope = null, $date = null, $includeTime = false)
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function scopeTimeStamp($scope = null)
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function formatDate($date = null, $format = \IntlDateFormatter::SHORT, $showTime = false)
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function isScopeDateInInterval($scope, $dateFrom = null, $dateTo = null)
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function formatDateTime(
        $date,
        $dateType = \IntlDateFormatter::SHORT,
        $timeType = \IntlDateFormatter::SHORT,
        $locale = null,
        $timezone = null,
        $pattern = null
    ) {
        throw new \LogicException('Not needed by DashboardTest.');
    }

    public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s')
    {
        throw new \LogicException('Not needed by DashboardTest.');
    }
}

/**
 * Stands in for the configured-rows source the dashboard resolves service labels through. Empty by
 * default, which is the state that makes a breakdown fall back to its raw `service_id`.
 */
final class FakeServiceSelector implements AiServiceSelectorInterface
{
    /** @var AiServiceInterface[] */
    private array $services = [];

    public function withService(AiServiceInterface $service): void
    {
        $this->services[] = $service;
    }

    /** @return AiServiceInterface[] */
    public function getAll(): array
    {
        return $this->services;
    }

    /** @return AiServiceInterface[] */
    public function getByCode(string $code): array
    {
        return array_values(array_filter(
            $this->services,
            static fn (AiServiceInterface $service): bool => $service->getCode() === $code
        ));
    }

    public function getById(string $id): ?AiServiceInterface
    {
        foreach ($this->services as $service) {
            if ($service->getId() === $id) {
                return $service;
            }
        }

        return null;
    }
}

/**
 * Minimal registered-backend stand-in, carrying only the code and display name the dashboard's
 * label lookup reads.
 */
final class FakeServiceConfiguration implements \MageOS\AiBase\Api\Data\AiServiceConfigurationInterface
{
    public function __construct(
        private readonly string $code,
        private readonly string $name,
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return \MageOS\AiBase\Api\Data\FieldDescriptorInterface[] */
    public function getConfigurationFields(): array
    {
        return [];
    }

    /** @return string[] */
    public function getSupportedModels(): array
    {
        return [];
    }
}

/**
 * In-memory stand-in for {@see StoreManagerInterface}, offering only the store list the dashboard's
 * scope selector reads. Everything else on that interface is outside what the block ever calls, so
 * it fails loudly rather than returning a plausible-looking empty value.
 */
final class FakeStoreManager implements StoreManagerInterface
{
    /** @var StoreInterface[] */
    private array $stores = [];

    public function withStore(int $id, string $name): self
    {
        $this->stores[] = new FakeStore($id, $name);

        return $this;
    }

    /**
     * Honours `$withDefault` the way the real manager does: store 0 is left out unless it is
     * asked for. The dashboard asks for it, and a fake that handed it over either way could not
     * tell a caller that asks from one that forgot to.
     *
     * @param bool $withDefault
     * @param bool $codeKey
     * @return StoreInterface[]
     */
    public function getStores($withDefault = false, $codeKey = false): array
    {
        return array_values(array_filter(
            $this->stores,
            static fn (StoreInterface $store): bool => $withDefault || (int) $store->getId() !== 0
        ));
    }

    public function setIsSingleStoreModeAllowed($value): void
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function hasSingleStore(): bool
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function isSingleStoreMode(): bool
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function getStore($storeId = null): StoreInterface
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function getWebsite($websiteId = null)
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function getWebsites($withDefault = false, $codeKey = false): array
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function reinitStores(): void
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function getDefaultStoreView(): ?StoreInterface
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function getGroup($groupId = null)
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function getGroups($withDefault = false): array
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }

    public function setCurrentStore($store): void
    {
        throw new \LogicException('FakeStoreManager only lists stores.');
    }
}

/**
 * The two accessors {@see FakeStoreManager} needs a store to answer, over Magento's data model so
 * the block sees the same interface it does in production.
 */
final class FakeStore extends \Magento\Framework\DataObject implements StoreInterface
{
    public function __construct(private readonly int $id, private readonly string $name)
    {
        parent::__construct();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id): self
    {
        throw new \LogicException('FakeStore is immutable.');
    }

    public function getCode(): string
    {
        return 'store_' . $this->id;
    }

    public function setCode($code): self
    {
        throw new \LogicException('FakeStore is immutable.');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName($name): self
    {
        throw new \LogicException('FakeStore is immutable.');
    }

    public function getWebsiteId()
    {
        return 1;
    }

    public function setWebsiteId($websiteId): self
    {
        throw new \LogicException('FakeStore is immutable.');
    }

    public function getStoreGroupId()
    {
        return 1;
    }

    public function setStoreGroupId($storeGroupId): self
    {
        throw new \LogicException('FakeStore is immutable.');
    }

    public function getExtensionAttributes()
    {
        return null;
    }

    public function setExtensionAttributes(
        \Magento\Store\Api\Data\StoreExtensionInterface $extensionAttributes
    ): self {
        throw new \LogicException('FakeStore is immutable.');
    }

    public function setIsActive($isActive): self
    {
        throw new \LogicException('FakeStore is immutable.');
    }

    public function getIsActive()
    {
        return true;
    }
}
