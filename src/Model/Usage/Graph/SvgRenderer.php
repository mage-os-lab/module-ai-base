<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Graph;

/**
 * Turns a list of {@see DataPoint}s into an inline SVG string.
 *
 * Magento 2.4 ships no charting library for the admin, and this module has to render under a
 * strict CSP with no external asset, so the graphs are hand-built markup rather than a JavaScript
 * chart library.
 *
 * This class is the entry point the dashboard talks to; the drawing itself lives in the collaborators
 * below, each with one job:
 *
 * - {@see BarChartRenderer} — horizontal bars
 * - {@see TrendRenderer} — single and multi-series lines, their axes, legend and tooltips
 * - {@see SvgDocument} — the `<svg>` wrapper, the empty state, and the namespace's only escaper
 * - {@see ChartValues} — scaling and number formatting
 * - {@see SeriesPalette} — the validated categorical slot order
 * - {@see ChartTheme} — the ink and type sizes all of the above share
 *
 * Escaping deliberately stays in exactly one place, {@see SvgDocument::escapeText()}: the finished
 * SVG is emitted with `@noEscape` because `Escaper::escapeHtml()` strips the geometry attributes an
 * SVG needs, so nothing downstream re-escapes it. A second escaper in this namespace would be a
 * second place for that to be forgotten.
 */
class SvgRenderer
{
    /**
     * Series label the palette treats as the folded tail rather than as a named series.
     *
     * Re-exposed here because callers hold a reference to this class, not to the palette; see
     * {@see SeriesPalette::OTHER_SERIES_LABEL} for the value itself.
     */
    public const OTHER_SERIES_LABEL = SeriesPalette::OTHER_SERIES_LABEL;

    /**
     * @param BarChartRenderer $barChartRenderer
     * @param TrendRenderer $trendRenderer
     */
    public function __construct(
        private readonly BarChartRenderer $barChartRenderer,
        private readonly TrendRenderer $trendRenderer,
    ) {
    }

    /**
     * Renders a horizontal bar chart, one bar per data point, scaled against the largest value.
     *
     * @param DataPoint[] $dataPoints
     * @param string $title
     * @return string
     */
    public function renderBarChart(array $dataPoints, string $title): string
    {
        return $this->barChartRenderer->renderBarChart($dataPoints, $title);
    }

    /**
     * Renders a trend across an ordered series of points, as a connected line.
     *
     * @param DataPoint[] $dataPoints
     * @param string $title
     * @return string
     */
    public function renderTrendChart(array $dataPoints, string $title): string
    {
        return $this->trendRenderer->renderTrendChart($dataPoints, $title);
    }

    /**
     * Renders several trends on one shared scale, one line per series, with a legend.
     *
     * @param array<string,DataPoint[]> $series Label => its ordered points
     * @param string $title
     * @return string
     */
    public function renderMultiTrendChart(array $series, string $title): string
    {
        return $this->trendRenderer->renderMultiTrendChart($series, $title);
    }
}
