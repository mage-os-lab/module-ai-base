<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Graph;

/**
 * Trend charts: one line for a single series, or one line per series on a shared scale.
 *
 * Always one y-axis. Two measures of different size go on two charts or are indexed to a common
 * base; a second scale on one plot invents a relationship the data does not have, and its alignment
 * is arbitrary.
 */
class TrendRenderer
{
    /**
     * Width of a trend chart, in SVG user units.
     *
     * Wider than the bar charts because it spans the dashboard rather than a half-width card, and
     * because a month of daily points needs the horizontal room to have a shape at all. Like every
     * chart here it is drawn at its intrinsic size and only ever scaled *down* by the stylesheet's
     * `max-width`, never up — scaling up would enlarge the text with it.
     */
    private const TREND_WIDTH = 1160.0;

    /**
     * Height of the plotting area of a trend chart, in SVG user units.
     */
    private const TREND_HEIGHT = 190.0;

    /**
     * Vertical margin inside a trend chart, in SVG user units.
     *
     * Sized for the tick label that sits above the topmost gridline rather than for the line
     * alone: at the plot's own height the peak label's ascender is cut off by the viewBox.
     */
    private const TREND_PADDING_Y = 22.0;

    /**
     * Room kept clear on the right of a trend chart for the label riding its final point. The
     * line stops short of the edge rather than the label being clipped by it.
     */
    private const TREND_PADDING_RIGHT = 52.0;

    /**
     * Radius of a plotted point. At or above the 4-unit floor a marker needs to be a comfortable
     * pointer target rather than something to be landed on dead centre.
     */
    private const TREND_MARKER_RADIUS = 4.0;

    /**
     * Most points a trend may mark individually. Past this the markers stop reading as data and
     * start reading as texture, so only the final point is marked and the line carries the rest.
     */
    private const TREND_MAX_MARKED_POINTS = 12;

    /**
     * Height of the band below a trend's plot that carries its date labels, in SVG user units.
     *
     * Counted into the chart's own height rather than left to overflow: a container sized to the
     * plot alone crops the axis or grows a nested scrollbar around it.
     */
    private const TREND_AXIS_HEIGHT = 22.0;

    /**
     * How many horizontal gridlines a trend draws, including the zero line.
     *
     * Three — zero, midpoint, peak — is enough to read a value off the line to within a glance,
     * and few enough that the grid stays behind the data instead of competing with it.
     */
    private const TREND_GRIDLINE_COUNT = 3;

    /**
     * Most date labels a trend's axis carries. Past this they collide, so the axis thins to an
     * evenly spaced subset that always keeps the first and last bucket.
     */
    private const TREND_MAX_AXIS_LABELS = 7;

    /**
     * Padding inside a hover tooltip's box, in SVG user units.
     */
    private const TOOLTIP_PADDING = 8.0;

    /**
     * Line height inside a hover tooltip, in SVG user units.
     */
    private const TOOLTIP_LINE_HEIGHT = 15.0;

    /**
     * Rough width of one character at {@see VALUE_FONT_SIZE}, in SVG user units.
     *
     * SVG cannot measure text server-side, so a tooltip's box is sized from an estimate. Erring
     * generous is deliberate: a box slightly wider than its text looks intentional, whereas a
     * box narrower than its text clips the value the tooltip exists to show.
     */
    private const TOOLTIP_CHARACTER_WIDTH = 6.4;

    /**
     * Fill of a hover tooltip's box: the admin's primary ink, so the panel reads as an overlay
     * above the plot rather than as another mark drawn into it.
     */
    private const TOOLTIP_FILL = '#1a202c';

    /**
     * Height of the legend strip above a multi-series plot, in SVG user units.
     */
    private const LEGEND_HEIGHT = 26.0;

    /**
     * Gap between one legend entry and the next, in SVG user units.
     */
    private const LEGEND_ENTRY_GAP = 18.0;

    /**
     * @param SvgDocument $document Wrapper and the namespace's only escaper
     * @param ChartValues $values Scaling and number formatting
     * @param SeriesPalette $palette Which colour each series is drawn in
     */
    public function __construct(
        private readonly SvgDocument $document,
        private readonly ChartValues $values,
        private readonly SeriesPalette $palette,
    ) {
    }

    /**
     * Renders a trend across an ordered series of points (typically one per day or month), as a
     * connected line rather than a set of comparable bars: the shape of the line, not any single
     * point, is what answers "is usage growing".
     *
     * @param DataPoint[] $dataPoints
     * @param string $title
     * @return string
     */
    public function renderTrendChart(array $dataPoints, string $title): string
    {
        if ($dataPoints === []) {
            return $this->document->renderEmptyState($title, self::TREND_WIDTH, self::TREND_HEIGHT);
        }

        $maxValue = $this->values->maxValue($dataPoints);
        $pointCount = count($dataPoints);
        $orderedPoints = array_values($dataPoints);

        $coordinates = array_map(
            fn (DataPoint $dataPoint, int $index): array
                => $this->trendCoordinate($dataPoint, $index, $pointCount, $maxValue),
            $orderedPoints,
            array_keys($orderedPoints)
        );

        $polyline = sprintf(
            '<polyline points="%s" fill="none" stroke="' . ChartTheme::BAR_COLOUR . '" stroke-width="2"'
            . ' stroke-linejoin="round" stroke-linecap="round" class="mageos-ai-usage-graph-trend"/>',
            implode(' ', array_map(
                fn (array $coordinate): string => sprintf('%s,%s', $coordinate['x'], $coordinate['y']),
                $coordinates
            ))
        );

        // Every point gets a marker only while the markers still read as data. Across a month of
        // days they merge into a dotted band, so past the cap the line carries the shape and only
        // its final point is marked — which is the point the reader is looking for anyway. Every
        // bucket stays readable regardless, through the hover bands below.
        $markedIndexes = $pointCount <= self::TREND_MAX_MARKED_POINTS
            ? array_keys($orderedPoints)
            : [$pointCount - 1];

        $markers = implode('', array_map(
            fn (int $index): string => $this->renderTrendMarker($orderedPoints[$index], $coordinates[$index]),
            $markedIndexes
        ));

        $endLabel = $this->renderTrendEndLabel(
            $orderedPoints[$pointCount - 1],
            $coordinates[$pointCount - 1]
        );

        return $this->document->wrapSvg(
            $this->renderTrendScale($maxValue)
            . $polyline
            . $markers
            . $endLabel
            . $this->renderTrendAxis($orderedPoints, $coordinates)
            . $this->renderTrendHoverBands($orderedPoints, $coordinates, $pointCount),
            $title,
            self::TREND_WIDTH,
            self::TREND_HEIGHT + self::TREND_AXIS_HEIGHT
        );
    }

    /**
     * Renders several trends on one shared scale, one line per series, with a legend.
     *
     * A legend is present for two or more series without exception: colour alone is never allowed
     * to be the only thing carrying identity. The lines share one y-axis — never a second scale,
     * which would invent a relationship the data does not have — so a series that is small stays
     * visibly small.
     *
     * @param array<string,DataPoint[]> $series Label => its ordered points; every series must carry
     *        the same bucket labels in the same order, which
     *        {@see \MageOS\AiBase\Api\UsageStatsInterface::getTimeSeriesByConsumer()} guarantees
     * @param string $title
     * @return string
     */
    public function renderMultiTrendChart(array $series, string $title): string
    {
        $series = array_filter($series, static fn (array $points): bool => $points !== []);
        if ($series === []) {
            return $this->document->renderEmptyState($title, self::TREND_WIDTH, self::TREND_HEIGHT);
        }

        $maxValue = $this->values->maxValue(array_merge(...array_values($series)));
        $firstSeries = array_values($series)[0];
        $pointCount = count($firstSeries);

        $lines = '';
        $slot = 0;
        foreach ($series as $seriesLabel => $points) {
            $colour = $this->palette->seriesColour((string) $seriesLabel, $slot);
            $lines .= $this->renderSeriesLine($points, $pointCount, $maxValue, $colour);
            $slot++;
        }

        $coordinates = array_map(
            fn (DataPoint $dataPoint, int $index): array
                => $this->trendCoordinate($dataPoint, $index, $pointCount, $maxValue),
            $firstSeries,
            array_keys($firstSeries)
        );

        return $this->document->wrapSvg(
            sprintf('<g transform="translate(0,%s)">%s</g>', self::LEGEND_HEIGHT, implode('', [
                $this->renderTrendScale($maxValue),
                $lines,
                $this->renderTrendAxis($firstSeries, $coordinates),
                $this->renderMultiTrendHoverBands($series, $coordinates, $pointCount),
            ])) . $this->renderLegend($series),
            $title,
            self::TREND_WIDTH,
            self::TREND_HEIGHT + self::TREND_AXIS_HEIGHT + self::LEGEND_HEIGHT
        );
    }

    /**
     * The horizontal gridlines and their value ticks.
     *
     * The ticks carry every value the chart does not directly label, which is what stops the
     * endpoint label and the tooltips being the only way to read the line. Each sits just above
     * its own line at the left edge rather than in a gutter beside the plot, so the trend keeps
     * the same left edge as the cards below it.
     *
     * @param float $maxValue
     * @return string
     */
    private function renderTrendScale(float $maxValue): string
    {
        $plottableHeight = self::TREND_HEIGHT - (self::TREND_PADDING_Y * 2);
        $lines = [];

        for ($tick = 0; $tick < self::TREND_GRIDLINE_COUNT; $tick++) {
            $ratio = $tick / (self::TREND_GRIDLINE_COUNT - 1);
            $y = round(self::TREND_HEIGHT - self::TREND_PADDING_Y - ($plottableHeight * $ratio), 2);

            $lines[] = sprintf(
                '<line class="mageos-ai-usage-graph-gridline" x1="0" y1="%s" x2="%s" y2="%s"'
                . ' stroke="%s" stroke-width="1"/>'
                . '<text class="mageos-ai-usage-graph-tick" x="0" y="%s" font-size="%s" fill="%s">%s</text>',
                $y,
                self::TREND_WIDTH,
                $y,
                ChartTheme::BASELINE_COLOUR,
                round($y - 4, 2),
                ChartTheme::VALUE_FONT_SIZE,
                ChartTheme::VALUE_COLOUR,
                $this->document->escapeText($this->values->formatAxisLabel($maxValue * $ratio))
            );
        }

        return implode('', $lines);
    }

    /**
     * The date labels below the plot.
     *
     * Thinned to an evenly spaced subset once they would collide, always keeping the first and
     * last bucket: those two are what tell a reader which window they are looking at. The first
     * and last are anchored inward so neither overhangs the chart's edges.
     *
     * @param DataPoint[] $orderedPoints
     * @param array<int,array{x:float,y:float}> $coordinates
     * @return string
     */
    private function renderTrendAxis(array $orderedPoints, array $coordinates): string
    {
        $pointCount = count($orderedPoints);
        $lastIndex = $pointCount - 1;
        $stride = (int) max(1, (int) ceil($pointCount / self::TREND_MAX_AXIS_LABELS));

        $indexes = range(0, $lastIndex, $stride);
        if (!in_array($lastIndex, $indexes, true)) {
            $indexes[] = $lastIndex;
        }

        // A label kept by the stride can land close enough to the last one to overlap it; the last
        // bucket wins, since it is the one the endpoint value belongs to.
        $indexes = array_values(array_filter(
            $indexes,
            static fn (int $index): bool => $index === $lastIndex || ($lastIndex - $index) >= $stride
        ));

        return implode('', array_map(
            function (int $index) use ($orderedPoints, $coordinates, $lastIndex): string {
                $anchor = match (true) {
                    $index === 0 => 'start',
                    $index === $lastIndex => 'end',
                    default => 'middle',
                };

                return sprintf(
                    '<text class="mageos-ai-usage-graph-axis-label" x="%s" y="%s" font-size="%s"'
                    . ' fill="%s" text-anchor="%s">%s</text>',
                    $coordinates[$index]['x'],
                    self::TREND_HEIGHT + 14,
                    ChartTheme::VALUE_FONT_SIZE,
                    ChartTheme::VALUE_COLOUR,
                    $anchor,
                    $this->document->escapeText($orderedPoints[$index]->getLabel())
                );
            },
            $indexes
        ));
    }

    /**
     * One transparent band per bucket, spanning the plot's full height.
     *
     * A line's markers are the only thing a pointer can land on otherwise, and past
     * {@see TREND_MAX_MARKED_POINTS} most buckets have no marker at all. The band makes every
     * bucket answer to a hover anywhere in its column, which is what keeps a tooltip an
     * enhancement rather than the gate on values the chart never printed.
     *
     * @param DataPoint[] $orderedPoints
     * @param array<int,array{x:float,y:float}> $coordinates
     * @param int $pointCount
     * @return string
     */
    private function renderTrendHoverBands(array $orderedPoints, array $coordinates, int $pointCount): string
    {
        $plottableWidth = self::TREND_WIDTH - self::TREND_PADDING_RIGHT;
        $bandWidth = $pointCount > 1 ? $plottableWidth / ($pointCount - 1) : $plottableWidth;

        return implode('', array_map(
            function (int $index) use ($orderedPoints, $coordinates, $bandWidth, $plottableWidth): string {
                // Each band is the half-step either side of its own point, clipped to the plot, so
                // the bands tile the width exactly once rather than overlapping — an overlap would
                // hand the hover to whichever band happened to be painted last.
                $bandStart = max(0.0, $coordinates[$index]['x'] - ($bandWidth / 2));
                $bandEnd = min($plottableWidth, $coordinates[$index]['x'] + ($bandWidth / 2));

                return sprintf(
                    '<g class="mageos-ai-usage-graph-hover">'
                    . '<rect class="mageos-ai-usage-graph-band" x="%s" y="0" width="%s" height="%s"'
                    . ' fill="transparent"/>%s</g>',
                    round($bandStart, 2),
                    round(max(0.0, $bandEnd - $bandStart), 2),
                    self::TREND_HEIGHT,
                    $this->renderTrendTooltip($orderedPoints[$index], $coordinates[$index])
                );
            },
            array_keys($orderedPoints)
        ));
    }

    /**
     * The crosshair, emphasised point and value panel a bucket shows while hovered.
     *
     * Hidden by default and revealed by the stylesheet on its band's hover, so the whole thing is
     * script-free and survives a strict CSP. Deliberately not a native `<title>`: that shows after
     * a delay, in the operating system's own tooltip, which cannot say which bucket it belongs to
     * without repeating the date as text.
     *
     * @param DataPoint $dataPoint
     * @param array{x:float,y:float} $coordinate
     * @return string
     */
    private function renderTrendTooltip(DataPoint $dataPoint, array $coordinate): string
    {
        $label = $dataPoint->getLabel();
        $value = $this->values->formatExactValue($dataPoint->getValue());
        $boxWidth = $this->tooltipWidth([$label, $value]);
        $boxHeight = (self::TOOLTIP_PADDING * 2) + (self::TOOLTIP_LINE_HEIGHT * 2);

        // Flip the panel to the left of the point once it would otherwise run past the plot, and
        // keep it clear of the top edge, so no tooltip is ever half outside the chart.
        $flip = ($coordinate['x'] + 12 + $boxWidth) > self::TREND_WIDTH;
        $boxX = $flip ? $coordinate['x'] - 12 - $boxWidth : $coordinate['x'] + 12;
        $boxY = max(0.0, min($coordinate['y'] - ($boxHeight / 2), self::TREND_HEIGHT - $boxHeight));
        $textX = round($boxX + self::TOOLTIP_PADDING, 2);

        return sprintf(
            '<g class="mageos-ai-usage-graph-tip">'
            . '<line x1="%s" y1="0" x2="%s" y2="%s" stroke="%s" stroke-width="1"/>'
            . '<circle cx="%s" cy="%s" r="5" fill="%s" stroke="%s" stroke-width="2"/>'
            . '<rect x="%s" y="%s" width="%s" height="%s" rx="4" fill="%s"/>'
            . '<text x="%s" y="%s" font-size="%s" fill="%s">%s</text>'
            . '<text x="%s" y="%s" font-size="%s" fill="%s" font-weight="600">%s</text>'
            . '</g>',
            $coordinate['x'],
            $coordinate['x'],
            self::TREND_HEIGHT,
            ChartTheme::BASELINE_COLOUR,
            $coordinate['x'],
            $coordinate['y'],
            ChartTheme::BAR_COLOUR,
            ChartTheme::SURFACE_COLOUR,
            round($boxX, 2),
            round($boxY, 2),
            round($boxWidth, 2),
            $boxHeight,
            self::TOOLTIP_FILL,
            $textX,
            round($boxY + self::TOOLTIP_PADDING + 11, 2),
            ChartTheme::VALUE_FONT_SIZE,
            ChartTheme::SURFACE_COLOUR,
            $this->document->escapeText($label),
            $textX,
            round($boxY + self::TOOLTIP_PADDING + 11 + self::TOOLTIP_LINE_HEIGHT, 2),
            ChartTheme::VALUE_FONT_SIZE,
            ChartTheme::SURFACE_COLOUR,
            $this->document->escapeText($value)
        );
    }

    /**
     * Hover bands for a multi-series chart: one panel per bucket listing every series at it.
     *
     * A per-series tooltip would make a reader hunt the right line before they could read a
     * number; the question at a bucket is what all of them did there.
     *
     * @param array<string,DataPoint[]> $series
     * @param array<int,array{x:float,y:float}> $coordinates
     * @param int $pointCount
     * @return string
     */
    private function renderMultiTrendHoverBands(array $series, array $coordinates, int $pointCount): string
    {
        $plottableWidth = self::TREND_WIDTH - self::TREND_PADDING_RIGHT;
        $bandWidth = $pointCount > 1 ? $plottableWidth / ($pointCount - 1) : $plottableWidth;

        $bands = [];
        for ($index = 0; $index < $pointCount; $index++) {
            $bandStart = max(0.0, $coordinates[$index]['x'] - ($bandWidth / 2));
            $bandEnd = min($plottableWidth, $coordinates[$index]['x'] + ($bandWidth / 2));

            $bands[] = sprintf(
                '<g class="mageos-ai-usage-graph-hover">'
                . '<rect class="mageos-ai-usage-graph-band" x="%s" y="0" width="%s" height="%s"'
                . ' fill="transparent"/>%s</g>',
                round($bandStart, 2),
                round(max(0.0, $bandEnd - $bandStart), 2),
                self::TREND_HEIGHT,
                $this->renderMultiTrendTooltip($series, $coordinates[$index], $index)
            );
        }

        return implode('', $bands);
    }

    /**
     * The panel one bucket shows: its label, then every series' value at it.
     *
     * @param array<string,DataPoint[]> $series
     * @param array{x:float,y:float} $coordinate
     * @param int $index
     * @return string
     */
    private function renderMultiTrendTooltip(array $series, array $coordinate, int $index): string
    {
        $bucketLabel = '';
        $rows = [];
        $slot = 0;

        foreach ($series as $seriesLabel => $points) {
            $ordered = array_values($points);
            if (!isset($ordered[$index])) {
                $slot++;
                continue;
            }
            $bucketLabel = $bucketLabel === '' ? $ordered[$index]->getLabel() : $bucketLabel;
            $rows[] = [
                'colour' => $this->palette->seriesColour((string) $seriesLabel, $slot),
                'text' => (string) $seriesLabel . '  ' . $this->values->formatExactValue($ordered[$index]->getValue()),
            ];
            $slot++;
        }

        $boxWidth = $this->tooltipWidth(array_merge(
            [$bucketLabel],
            array_map(static fn (array $row): string => '     ' . $row['text'], $rows)
        ));
        $boxHeight = (self::TOOLTIP_PADDING * 2) + (self::TOOLTIP_LINE_HEIGHT * (count($rows) + 1));

        $flip = ($coordinate['x'] + 12 + $boxWidth) > self::TREND_WIDTH;
        $boxX = $flip ? $coordinate['x'] - 12 - $boxWidth : $coordinate['x'] + 12;
        // Centred vertically rather than tracked to a point: with several lines there is no
        // single point the panel belongs to.
        $boxY = max(0.0, min((self::TREND_HEIGHT / 2) - ($boxHeight / 2), self::TREND_HEIGHT - $boxHeight));
        $textX = round($boxX + self::TOOLTIP_PADDING, 2);

        $body = sprintf(
            '<text x="%s" y="%s" font-size="%s" fill="%s" font-weight="600">%s</text>',
            $textX,
            round($boxY + self::TOOLTIP_PADDING + 11, 2),
            ChartTheme::VALUE_FONT_SIZE,
            ChartTheme::SURFACE_COLOUR,
            $this->document->escapeText($bucketLabel)
        );

        foreach ($rows as $rowIndex => $row) {
            $lineY = round($boxY + self::TOOLTIP_PADDING + 11 + (self::TOOLTIP_LINE_HEIGHT * ($rowIndex + 1)), 2);
            $body .= sprintf(
                '<rect x="%s" y="%s" width="8" height="8" rx="2" fill="%s"/>'
                . '<text x="%s" y="%s" font-size="%s" fill="%s">%s</text>',
                $textX,
                round($lineY - 8, 2),
                $row['colour'],
                round($textX + 13, 2),
                $lineY,
                ChartTheme::VALUE_FONT_SIZE,
                ChartTheme::SURFACE_COLOUR,
                $this->document->escapeText($row['text'])
            );
        }

        return sprintf(
            '<g class="mageos-ai-usage-graph-tip">'
            . '<line x1="%s" y1="0" x2="%s" y2="%s" stroke="%s" stroke-width="1"/>'
            . '<rect x="%s" y="%s" width="%s" height="%s" rx="4" fill="%s"/>%s</g>',
            $coordinate['x'],
            $coordinate['x'],
            self::TREND_HEIGHT,
            ChartTheme::BASELINE_COLOUR,
            round($boxX, 2),
            round($boxY, 2),
            round($boxWidth, 2),
            $boxHeight,
            self::TOOLTIP_FILL,
            $body
        );
    }

    /**
     * One series' polyline.
     *
     * @param DataPoint[] $points
     * @param int $pointCount
     * @param float $maxValue
     * @param string $colour
     * @return string
     */
    private function renderSeriesLine(array $points, int $pointCount, float $maxValue, string $colour): string
    {
        $ordered = array_values($points);
        $coordinates = array_map(
            fn (DataPoint $dataPoint, int $index): array
                => $this->trendCoordinate($dataPoint, $index, $pointCount, $maxValue),
            $ordered,
            array_keys($ordered)
        );

        return sprintf(
            '<polyline class="mageos-ai-usage-graph-trend" points="%s" fill="none" stroke="%s"'
            . ' stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>',
            implode(' ', array_map(
                fn (array $coordinate): string => sprintf('%s,%s', $coordinate['x'], $coordinate['y']),
                $coordinates
            )),
            $colour
        );
    }

    /**
     * The legend strip: a colour key and the series name, in the order the series are drawn.
     *
     * Entries are laid out from an estimated text width for the same reason a tooltip box is
     * (see {@see tooltipWidth()}); SVG cannot measure text before it renders.
     *
     * @param array<string,DataPoint[]> $series
     * @return string
     */
    private function renderLegend(array $series): string
    {
        $entries = [];
        $offset = 0.0;
        $slot = 0;

        foreach (array_keys($series) as $seriesLabel) {
            $label = (string) $seriesLabel;
            $colour = $this->palette->seriesColour($label, $slot);

            $entries[] = sprintf(
                '<g class="mageos-ai-usage-graph-legend-entry">'
                . '<rect x="%s" y="4" width="10" height="10" rx="2" fill="%s"/>'
                . '<text x="%s" y="13" font-size="%s" fill="%s">%s</text></g>',
                round($offset, 2),
                $colour,
                round($offset + 15, 2),
                ChartTheme::VALUE_FONT_SIZE,
                ChartTheme::LABEL_COLOUR,
                $this->document->escapeText($label)
            );

            $offset += 15 + (mb_strlen($label) * self::TOOLTIP_CHARACTER_WIDTH) + self::LEGEND_ENTRY_GAP;
            $slot++;
        }

        return sprintf('<g class="mageos-ai-usage-graph-legend">%s</g>', implode('', $entries));
    }

    /**
     * Width a tooltip box needs for its longest line, from an estimate rather than a measurement.
     *
     * @param string[] $lines
     * @return float
     */
    private function tooltipWidth(array $lines): float
    {
        $longest = array_reduce(
            $lines,
            static fn (int $carry, string $line): int => max($carry, mb_strlen($line)),
            0
        );

        return (self::TOOLTIP_PADDING * 2) + ($longest * self::TOOLTIP_CHARACTER_WIDTH);
    }

    /**
     * Plotting position of a single trend point, scaled against the series and the chart area.
     *
     * Guards both the horizontal step (a single-point series has nothing to space out) and the
     * vertical scale (an all-zero series has no range to scale into) explicitly, the same way
     * {@see barWidth()} guards a bar chart's scale, rather than dividing by a count or a maximum
     * that can legitimately be zero.
     *
     * @param DataPoint $dataPoint
     * @param int $index
     * @param int $pointCount
     * @param float $maxValue
     * @return array{x:float,y:float}
     */
    private function trendCoordinate(DataPoint $dataPoint, int $index, int $pointCount, float $maxValue): array
    {
        $plottableWidth = self::TREND_WIDTH - self::TREND_PADDING_RIGHT;
        $step = $pointCount > 1 ? $plottableWidth / ($pointCount - 1) : 0.0;
        $x = round($step * $index, 2);

        $plottableHeight = self::TREND_HEIGHT - (self::TREND_PADDING_Y * 2);
        $ratio = $maxValue > 0.0 ? $dataPoint->getValue() / $maxValue : 0.0;
        $y = round(self::TREND_HEIGHT - self::TREND_PADDING_Y - ($plottableHeight * $ratio), 2);

        return ['x' => $x, 'y' => $y];
    }

    /**
     * A marker at a plotted trend point.
     *
     * Carries the same exact-value tooltip a bar chart's bars carry, since a point on a line has
     * no other way to show its precise value.
     *
     * @param DataPoint $dataPoint
     * @param array{x:float,y:float} $coordinate
     * @return string
     */
    private function renderTrendMarker(DataPoint $dataPoint, array $coordinate): string
    {
        // The surface-coloured ring is what keeps a marker legible where it sits on the line or
        // beside its neighbour, and it counts toward the marker's hit area rather than being
        // decoration around it.
        return sprintf(
            '<circle class="mageos-ai-usage-graph-point" cx="%s" cy="%s" r="%s" fill="%s"'
            . ' stroke="%s" stroke-width="2"><title>%s</title></circle>',
            $coordinate['x'],
            $coordinate['y'],
            self::TREND_MARKER_RADIUS,
            ChartTheme::BAR_COLOUR,
            ChartTheme::SURFACE_COLOUR,
            $this->document->escapeText(
                $dataPoint->getLabel() . ': ' . $this->values->formatExactValue($dataPoint->getValue())
            )
        );
    }

    /**
     * The value riding the last point of a trend.
     *
     * The one direct label a trend carries: labelling every point is chaos and goes unread, and
     * the endpoint is where a reader looks to answer "where has it got to". Every other point
     * keeps its exact figure in its own marker tooltip.
     *
     * @param DataPoint $dataPoint
     * @param array{x:float,y:float} $coordinate
     * @return string
     */
    private function renderTrendEndLabel(DataPoint $dataPoint, array $coordinate): string
    {
        return sprintf(
            '<text class="mageos-ai-usage-graph-value" x="%s" y="%s" font-size="%s" fill="%s">%s</text>',
            round($coordinate['x'] + 10, 2),
            round($coordinate['y'] + 4, 2),
            ChartTheme::VALUE_FONT_SIZE,
            ChartTheme::VALUE_COLOUR,
            $this->document->escapeText($this->values->formatAxisLabel($dataPoint->getValue()))
        );
    }
}
