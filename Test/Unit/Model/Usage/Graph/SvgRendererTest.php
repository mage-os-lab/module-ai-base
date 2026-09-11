<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage\Graph;

use MageOS\AiBase\Model\Usage\Graph\DataPoint;
use MageOS\AiBase\Model\Usage\Graph\BarChartRenderer;
use MageOS\AiBase\Model\Usage\Graph\ChartValues;
use MageOS\AiBase\Model\Usage\Graph\SeriesPalette;
use MageOS\AiBase\Model\Usage\Graph\SvgDocument;
use MageOS\AiBase\Model\Usage\Graph\SvgRenderer;
use MageOS\AiBase\Model\Usage\Graph\TrendRenderer;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * @covers \MageOS\AiBase\Model\Usage\Graph\SvgRenderer
 */
final class SvgRendererTest extends TestCase
{
    private SvgRenderer $subject;

    protected function setUp(): void
    {
        $this->subject = self::renderer();
    }

    public function test_it_renders_a_bar_for_every_data_point(): void
    {
        $svg = $this->subject->renderBarChart(
            [
                new DataPoint('Consumer A', 10.0),
                new DataPoint('Consumer B', 20.0),
                new DataPoint('Consumer C', 30.0),
            ],
            'By consumer'
        );

        self::assertCount(3, $this->bars($svg));
    }

    public function test_it_scales_bar_widths_against_the_largest_value(): void
    {
        $svg = $this->subject->renderBarChart(
            [
                new DataPoint('Small', 10.0),
                new DataPoint('Largest', 100.0),
                new DataPoint('Half', 50.0),
            ],
            'By consumer'
        );

        $widths = $this->barWidths($svg);

        self::assertSame($widths[1], max($widths), 'The bar for the largest value must be the widest.');
        self::assertEqualsWithDelta($widths[1] / 2, $widths[2], 0.01, 'A half-sized value must render a half-width bar.');
        self::assertGreaterThan($widths[0], $widths[1]);
    }

    public function test_it_escapes_html_special_characters_in_a_data_point_label(): void
    {
        $svg = $this->subject->renderBarChart(
            [new DataPoint('Tom & Jerry <admin>', 10.0)],
            'By consumer'
        );

        self::assertStringNotContainsString('<admin>', $svg);
        self::assertStringContainsString('Tom &amp; Jerry &lt;admin&gt;', $svg);

        $labels = $this->xpath($svg, '//*[local-name()="text"]');
        self::assertSame('Tom & Jerry <admin>', (string) $labels[0]);
    }

    public function test_it_escapes_a_label_that_tries_to_close_the_svg_text_element(): void
    {
        $svg = $this->subject->renderBarChart(
            [new DataPoint('</text><rect x="0" y="0" width="9999" height="9999" fill="red"/>', 10.0)],
            'By consumer'
        );

        self::assertStringNotContainsString('</text><rect', $svg);
        self::assertCount(1, $this->xpath($svg, '//*[local-name()="rect"]'));
        self::assertCount(2, $this->xpath($svg, '//*[local-name()="text"]'));
    }

    public function test_it_escapes_a_label_that_tries_to_open_a_script_element(): void
    {
        $svg = $this->subject->renderBarChart(
            [new DataPoint('<script>alert(document.cookie)</script>', 10.0)],
            'By consumer'
        );

        self::assertStringNotContainsString('<script>', $svg);
        self::assertCount(0, $this->xpath($svg, '//*[local-name()="script"]'));
    }

    public function test_it_escapes_quotes_in_a_label_rendered_inside_a_title_element(): void
    {
        $svg = $this->subject->renderBarChart(
            [new DataPoint('Bob\'s "VIP" store', 10.0)],
            'By consumer'
        );

        self::assertStringNotContainsString('"VIP"', $svg);
        self::assertStringContainsString('&quot;VIP&quot;', $svg);
        self::assertStringContainsString('&apos;s', $svg);

        $titles = $this->rowTitles($svg);
        self::assertStringContainsString('Bob\'s "VIP" store', (string) $titles[0]);
    }

    public function test_it_renders_an_empty_state_when_there_are_no_data_points(): void
    {
        $svg = $this->subject->renderBarChart([], 'By consumer');

        self::assertCount(0, $this->xpath($svg, '//*[local-name()="rect"]'));
        self::assertInstanceOf(SimpleXMLElement::class, simplexml_load_string($svg));
        self::assertStringContainsString('No usage recorded', $svg);
    }

    public function test_it_renders_without_dividing_by_zero_when_every_value_is_zero(): void
    {
        $svg = $this->subject->renderBarChart(
            [
                new DataPoint('Consumer A', 0.0),
                new DataPoint('Consumer B', 0.0),
            ],
            'By consumer'
        );

        self::assertStringNotContainsString('NAN', $svg);
        self::assertStringNotContainsString('INF', $svg);

        $widths = $this->barWidths($svg);

        self::assertSame([], $this->barWidths($svg), 'A zero value draws no bar, only its row.');
    }

    public function test_it_renders_a_readable_axis_label_for_a_value_in_the_millions(): void
    {
        $svg = $this->subject->renderBarChart(
            [new DataPoint('Consumer A', 1204331.0)],
            'By consumer'
        );

        self::assertStringNotContainsString('1204331', $svg);
        self::assertStringContainsString('1.2M', $svg);
    }

    public function test_it_gives_every_bar_a_title_element_carrying_the_exact_value(): void
    {
        $svg = $this->subject->renderBarChart(
            [
                new DataPoint('Consumer A', 1204331.0),
                new DataPoint('Consumer B', 42.0),
            ],
            'By consumer'
        );

        $titles = array_map('strval', $this->rowTitles($svg));

        self::assertCount(2, $titles);
        self::assertStringContainsString('1,204,331', $titles[0]);
        self::assertStringContainsString('42', $titles[1]);
    }

    public function test_it_renders_a_trend_series_across_the_given_points(): void
    {
        $svg = $this->subject->renderTrendChart(
            [
                new DataPoint('Mon', 10.0),
                new DataPoint('Tue', 40.0),
                new DataPoint('Wed', 25.0),
                new DataPoint('Thu', 60.0),
            ],
            'Tokens over time'
        );

        $polylines = $this->xpath($svg, '//*[local-name()="polyline"]');
        self::assertCount(1, $polylines);

        $points = trim((string) $polylines[0]['points']);
        $coordinatePairs = preg_split('/\s+/', $points);
        self::assertIsArray($coordinatePairs);
        self::assertCount(4, $coordinatePairs);
    }

    public function test_it_produces_identical_output_for_identical_input(): void
    {
        $dataPoints = [
            new DataPoint('Consumer A', 10.0),
            new DataPoint('Consumer B', 1204331.0),
            new DataPoint('Consumer C', 0.0),
        ];

        $first = $this->subject->renderBarChart($dataPoints, 'By consumer');
        $second = $this->subject->renderBarChart(
            [
                new DataPoint('Consumer A', 10.0),
                new DataPoint('Consumer B', 1204331.0),
                new DataPoint('Consumer C', 0.0),
            ],
            'By consumer'
        );

        self::assertSame($first, $second);

        $firstTrend = $this->subject->renderTrendChart($dataPoints, 'Tokens over time');
        $secondTrend = $this->subject->renderTrendChart($dataPoints, 'Tokens over time');

        self::assertSame($firstTrend, $secondTrend);
    }

    public function test_it_gives_every_trend_bucket_a_hover_tooltip_carrying_its_exact_value(): void
    {
        $svg = $this->subject->renderTrendChart(
            [
                new DataPoint('1 Sep', 1204331.0),
                new DataPoint('2 Sep', 42.0),
            ],
            'Tokens over time'
        );

        $tips = $this->xpath($svg, '//*[local-name()="g"][@class="mageos-ai-usage-graph-tip"]');
        self::assertCount(2, $tips, 'Every bucket is hoverable, not only the ones with a marker.');
        $tipText = array_map(
            static fn (SimpleXMLElement $text): string => (string) $text,
            $this->xpath(
                $svg,
                '//*[local-name()="g"][@class="mageos-ai-usage-graph-tip"]/*[local-name()="text"]'
            )
        );

        // Exact figures, not the axis's abbreviated ones: the tooltip is where a reader goes for
        // the real number behind a point.
        self::assertContains('1,204,331', $tipText);
        self::assertContains('42', $tipText);
        self::assertContains('1 Sep', $tipText);
    }

    public function test_it_tiles_the_hover_bands_without_overlapping(): void
    {
        $svg = $this->subject->renderTrendChart(
            [
                new DataPoint('1 Sep', 10.0),
                new DataPoint('2 Sep', 20.0),
                new DataPoint('3 Sep', 30.0),
            ],
            'Tokens over time'
        );

        $bands = $this->xpath($svg, '//*[local-name()="rect"][@class="mageos-ai-usage-graph-band"]');
        $edges = array_map(
            static fn (SimpleXMLElement $band): array => [(float) $band['x'], (float) $band['x'] + (float) $band['width']],
            $bands
        );

        self::assertCount(3, $edges);
        foreach ($edges as $index => [$start, $end]) {
            self::assertGreaterThan($start, $end, 'A band with no width cannot be hovered.');
            if ($index > 0) {
                self::assertEqualsWithDelta($edges[$index - 1][1], $start, 0.01, 'Bands must tile, not overlap.');
            }
        }
    }

    public function test_it_keeps_a_trend_tooltip_inside_the_chart_at_the_final_bucket(): void
    {
        $svg = $this->subject->renderTrendChart(
            [
                new DataPoint('1 Sep', 10.0),
                new DataPoint('30 Sep', 20.0),
            ],
            'Tokens over time'
        );

        $boxes = $this->xpath(
            $svg,
            '//*[local-name()="g"][@class="mageos-ai-usage-graph-tip"]/*[local-name()="rect"]'
        );
        $last = end($boxes);
        self::assertNotFalse($last);

        // The panel for the final bucket has to flip to the left of its point, or it renders
        // partly outside the viewBox and is clipped.
        self::assertGreaterThanOrEqual(0.0, (float) $last['x']);
    }

    public function test_it_escapes_a_label_rendered_into_a_trend_tooltip(): void
    {
        $svg = $this->subject->renderTrendChart(
            [new DataPoint('<script>alert(1)</script>', 10.0)],
            'Tokens over time'
        );

        self::assertStringNotContainsString('<script>', $svg);
        self::assertCount(0, $this->xpath($svg, '//*[local-name()="script"]'));
    }

    /**
     * The bar marks of a chart: the path per row that actually encodes a value. Selected by class
     * rather than by element name, because a row also contains a transparent hit rect and two
     * text nodes.
     *
     * @param string $svg
     * @return SimpleXMLElement[]
     */
    private function bars(string $svg): array
    {
        return $this->xpath($svg, '//*[local-name()="path"][@class="mageos-ai-usage-graph-bar"]');
    }

    /**
     * Plotted length of every bar, in the order they were drawn.
     *
     * @param string $svg
     * @return float[]
     */
    private function barWidths(string $svg): array
    {
        return array_map(
            static fn (SimpleXMLElement $bar): float => (float) $bar['data-width'],
            $this->bars($svg)
        );
    }

    /**
     * The per-row tooltips. One `<title>` hangs off each row group, so a hover anywhere on the
     * row answers; the chart's own `<title>` on the root is deliberately excluded.
     *
     * @param string $svg
     * @return SimpleXMLElement[]
     */
    private function rowTitles(string $svg): array
    {
        return $this->xpath(
            $svg,
            '//*[local-name()="g"][@class="mageos-ai-usage-graph-row"]/*[local-name()="title"]'
        );
    }

    /**
     * Parses the rendered SVG and runs an XPath query against it, ignoring the default SVG
     * namespace so `local-name()` matching stays readable in the tests that use this.
     *
     * @param string $svg
     * @param string $query
     * @return SimpleXMLElement[]
     */
    private function xpath(string $svg, string $query): array
    {
        $document = simplexml_load_string($svg);
        self::assertInstanceOf(SimpleXMLElement::class, $document, "Rendered SVG did not parse:\n" . $svg);

        $result = $document->xpath($query);
        self::assertIsArray($result);

        return $result;
    }

    /**
     * Composes the renderer the way `di.xml` does, so a test builds the same object graph the
     * admin renders with rather than a stand-in of it.
     */
    private static function renderer(): SvgRenderer
    {
        $document = new SvgDocument();
        $values = new ChartValues();

        return new SvgRenderer(
            new BarChartRenderer($document, $values),
            new TrendRenderer($document, $values, new SeriesPalette())
        );
    }
}
