<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Graph;

/**
 * Horizontal bar charts: one bar per data point, scaled against the largest value in the set.
 *
 * One hue for every bar, never a ramp: the categories a usage breakdown names have no natural
 * order, and colouring them darker-where-bigger would encode the bar's length a second time in the
 * only channel left free.
 */
class BarChartRenderer
{
    /**
     * Widest a bar is ever drawn, in SVG user units. The largest value in a chart fills exactly
     * this width; every other bar is a fraction of it, which is what makes the chart a comparison
     * rather than a list of unrelated numbers.
     */
    private const MAX_BAR_WIDTH = 440.0;

    /**
     * Height of a single bar, in SVG user units.
     */
    private const BAR_HEIGHT = 14.0;

    /**
     * Vertical space a bar's row occupies, including the label drawn above it. Taller than
     * {@see BAR_HEIGHT} alone so consecutive rows do not overlap.
     */
    private const BAR_ROW_HEIGHT = 40.0;

    /**
     * Horizontal margin inside a chart, in SVG user units: none.
     *
     * The chart is drawn flush to its own left edge so its labels and bars line up with the card
     * heading above them. Padding here would be a second, competing inset on top of the card's
     * own, which is what pushed the plot 8px out of the card's text column.
     */
    private const CHART_PADDING_X = 0.0;

    /**
     * Vertical margin inside a chart, in SVG user units. Small, because the card already provides
     * the outer breathing room; this only stops the first and last row touching the edge.
     */
    private const CHART_PADDING_Y = 4.0;

    /**
     * Where the baseline rule is drawn, in SVG user units. Half a unit in from the edge so its
     * 1px stroke lands whole inside the viewBox instead of being clipped down the middle.
     */
    private const BASELINE_OFFSET = 0.5;

    /**
     * Baseline of a bar's label within its row, in SVG user units from the row's top. Sits far
     * enough above {@see BAR_HEIGHT}'s band that a descender in the label never touches the bar.
     */
    private const LABEL_BASELINE_OFFSET = 13.0;

    /**
     * Corner radius of a bar's data end, in SVG user units. Only the end the value reaches is
     * rounded; the baseline end stays square, so every bar visibly starts from the same edge
     * instead of appearing to float just off it.
     */
    private const BAR_CORNER_RADIUS = 4.0;

    /**
     * Narrowest a non-zero bar is ever drawn, in SVG user units. A value that rounds to less than
     * a pixel would otherwise vanish entirely and read as "no usage" rather than "barely any",
     * which is a different statement. A true zero still draws no bar at all.
     */
    private const MIN_VISIBLE_BAR_WIDTH = 2.0;

    /**
     * Width of a bar chart, in SVG user units. Wide enough for {@see MAX_BAR_WIDTH} plus room
     * for the axis value label drawn after the longest bar.
     */
    private const BAR_CHART_WIDTH = 600.0;

    /**
     * @param SvgDocument $document Wrapper and the namespace's only escaper
     * @param ChartValues $values Scaling and number formatting
     */
    public function __construct(
        private readonly SvgDocument $document,
        private readonly ChartValues $values,
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
        if ($dataPoints === []) {
            $emptyStateHeight = self::BAR_ROW_HEIGHT + (self::CHART_PADDING_Y * 2);

            return $this->document->renderEmptyState($title, self::BAR_CHART_WIDTH, $emptyStateHeight);
        }

        $maxValue = $this->values->maxValue($dataPoints);
        $bars = array_map(
            fn (DataPoint $dataPoint, int $index): string => $this->renderBar($dataPoint, $index, $maxValue),
            array_values($dataPoints),
            array_keys(array_values($dataPoints))
        );

        $height = (self::CHART_PADDING_Y * 2) + (count($dataPoints) * self::BAR_ROW_HEIGHT);

        return $this->document->wrapSvg(
            $this->renderBaseline($height) . implode('', $bars),
            $title,
            self::BAR_CHART_WIDTH,
            $height
        );
    }

    /**
     * Width of a single bar, scaled so the largest value across the chart fills
     * {@see MAX_BAR_WIDTH} exactly. Guards the division explicitly rather than relying on PHP's
     * float division-by-zero warning, because a chart where every value is zero is a real,
     * expected input (a consumer with no usage yet), not a caller error.
     *
     * @param float $value
     * @param float $maxValue
     * @return float
     */
    private function barWidth(float $value, float $maxValue): float
    {
        if ($maxValue <= 0.0) {
            return 0.0;
        }

        return round(($value / $maxValue) * self::MAX_BAR_WIDTH, 2);
    }

    /**
     * Markup for a single bar.
     *
     * Positioned in its row by `$index` so consecutive bars stack downward rather than drawing
     * on top of one another.
     *
     * @param DataPoint $dataPoint
     * @param int $index
     * @param float $maxValue
     * @return string
     */
    private function renderBar(DataPoint $dataPoint, int $index, float $maxValue): string
    {
        $rowTop = self::CHART_PADDING_Y + ($index * self::BAR_ROW_HEIGHT);
        $barTop = $rowTop + (self::BAR_ROW_HEIGHT - self::BAR_HEIGHT) - 6;
        $barWidth = $this->barWidth($dataPoint->getValue(), $maxValue);
        $tooltip = $this->document->escapeText(
            $dataPoint->getLabel() . ': ' . $this->values->formatExactValue($dataPoint->getValue())
        );

        // The row is one group carrying one <title>, over a transparent rect spanning the full
        // row: the tooltip then answers to a hover anywhere on the row rather than only to the
        // 14px band of the bar itself, which is smaller than a comfortable pointer target and
        // vanishes altogether for a near-zero value.
        return sprintf(
            '<g class="mageos-ai-usage-graph-row"><title>%s</title>'
            . '<rect class="mageos-ai-usage-graph-hit" x="0" y="%s" width="%s" height="%s" fill="transparent"/>'
            . '<text x="%s" y="%s" font-size="%s" fill="%s" class="mageos-ai-usage-graph-label">%s</text>'
            . '%s'
            . '<text x="%s" y="%s" font-size="%s" fill="%s" class="mageos-ai-usage-graph-value">%s</text>'
            . '</g>',
            $tooltip,
            $rowTop,
            self::BAR_CHART_WIDTH,
            self::BAR_ROW_HEIGHT,
            self::CHART_PADDING_X,
            $rowTop + self::LABEL_BASELINE_OFFSET,
            ChartTheme::LABEL_FONT_SIZE,
            ChartTheme::LABEL_COLOUR,
            $this->document->escapeText($dataPoint->getLabel()),
            $this->renderBarMark($barTop, $barWidth),
            self::CHART_PADDING_X + $barWidth + 6,
            $barTop + (self::BAR_HEIGHT / 2) + 4,
            ChartTheme::VALUE_FONT_SIZE,
            ChartTheme::VALUE_COLOUR,
            $this->document->escapeText($this->values->formatAxisLabel($dataPoint->getValue()))
        );
    }

    /**
     * The bar itself, or nothing at all when the value is zero.
     *
     * A zero value draws no mark rather than a hairline one: the row still carries its label and
     * its `0`, so nothing is hidden, and an empty track is the honest picture of a consumer that
     * has spent nothing.
     *
     * @param float $barTop
     * @param float $barWidth
     * @return string
     */
    private function renderBarMark(float $barTop, float $barWidth): string
    {
        if ($barWidth <= 0.0) {
            return '';
        }

        $drawnWidth = max($barWidth, self::MIN_VISIBLE_BAR_WIDTH);

        // `data-width` restates the geometry the path encodes. The path's own coordinates carry
        // the corner radius folded into them, so the plotted length is not directly readable from
        // `d` by anything inspecting the chart — an export script, a snapshot test, or a person in
        // devtools. Stating it once here keeps the mark's meaning legible without a second element.
        return sprintf(
            '<path class="mageos-ai-usage-graph-bar" fill="%s" data-width="%s" d="%s"/>',
            ChartTheme::BAR_COLOUR,
            $drawnWidth,
            $this->barPath($barTop, $drawnWidth)
        );
    }

    /**
     * Path of a bar: square where it meets the baseline, rounded where the value ends.
     *
     * Drawn as a path rather than a `<rect rx>` because `rx` rounds all four corners, which
     * detaches the bar from the baseline it is measured against. A bar narrower than the corner
     * radius has no room for the arcs and is drawn square, since rounding it would round away
     * most of its length.
     *
     * @param float $barTop
     * @param float $barWidth
     * @return string
     */
    private function barPath(float $barTop, float $barWidth): string
    {
        $left = self::CHART_PADDING_X;
        $right = $left + $barWidth;
        $bottom = $barTop + self::BAR_HEIGHT;

        if ($barWidth <= self::BAR_CORNER_RADIUS) {
            return sprintf('M%s %s H%s V%s H%s Z', $left, $barTop, $right, $bottom, $left);
        }

        $radius = self::BAR_CORNER_RADIUS;

        return sprintf(
            'M%s %s H%s A%s %s 0 0 1 %s %s V%s A%s %s 0 0 1 %s %s H%s Z',
            $left,
            $barTop,
            round($right - $radius, 2),
            $radius,
            $radius,
            $right,
            round($barTop + $radius, 2),
            round($bottom - $radius, 2),
            $radius,
            $radius,
            round($right - $radius, 2),
            $bottom,
            $left
        );
    }

    /**
     * The hairline every bar in a chart grows from, spanning the plotted rows.
     *
     * @param float $height
     * @return string
     */
    private function renderBaseline(float $height): string
    {
        return sprintf(
            '<line class="mageos-ai-usage-graph-baseline" x1="%s" y1="%s" x2="%s" y2="%s"'
            . ' stroke="%s" stroke-width="1"/>',
            self::BASELINE_OFFSET,
            self::CHART_PADDING_Y,
            self::BASELINE_OFFSET,
            $height - self::CHART_PADDING_Y,
            ChartTheme::BASELINE_COLOUR
        );
    }
}
