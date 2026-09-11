<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Graph;

/**
 * The numbers a chart states, and the one number it scales against.
 *
 * Formatting lives here rather than in the renderers so an axis label and a tooltip figure can
 * never disagree about how the same value reads, and so a renderer is not also a number formatter.
 */
class ChartValues
{
    /**
     * Renders a value abbreviated to one decimal place with a magnitude suffix (`1.2M` rather
     * than `1204331`), for the label drawn next to a bar. Large token counts are unreadable at a
     * glance otherwise; the exact figure is never lost, since it is still available in the bar's
     * {@see renderBar() title}.
     *
     * @param float $value
     * @return string
     */
    public function formatAxisLabel(float $value): string
    {
        $absolute = abs($value);

        if ($absolute >= 1_000_000.0) {
            return $this->formatMagnitude($value, 1_000_000.0, 'M');
        }

        if ($absolute >= 1_000.0) {
            return $this->formatMagnitude($value, 1_000.0, 'k');
        }

        return $this->formatExactValue($value);
    }

    /**
     * Divides `$value` by `$divisor`, rounds to one decimal place and appends `$suffix`.
     *
     * Trims a trailing `.0` so a round million reads `1M` rather than `1.0M`.
     *
     * @param float $value
     * @param float $divisor
     * @param string $suffix
     * @return string
     */
    private function formatMagnitude(float $value, float $divisor, string $suffix): string
    {
        $scaled = round($value / $divisor, 1);

        return rtrim(rtrim(number_format($scaled, 1), '0'), '.') . $suffix;
    }

    /**
     * Renders a value exactly, with thousands grouped for readability, rather than abbreviated.
     * Used for the per-bar `<title>` (the exact tooltip value a hovering admin needs), never for
     * the chart axis, which goes through {@see formatAxisLabel()} instead.
     *
     * @param float $value
     * @return string
     */
    public function formatExactValue(float $value): string
    {
        if (fmod($value, 1.0) === 0.0) {
            return number_format($value, 0);
        }

        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    /**
     * Largest value across a set of data points, or `0.0` when the set is empty. The `0.0`
     * fallback is what lets {@see barWidth()} treat "no data" and "all values zero" identically,
     * both rendering a zero-width bar rather than dividing by zero.
     *
     * @param DataPoint[] $dataPoints
     * @return float
     */
    public function maxValue(array $dataPoints): float
    {
        return array_reduce(
            $dataPoints,
            fn (float $carry, DataPoint $dataPoint): float => max($carry, $dataPoint->getValue()),
            0.0
        );
    }
}
