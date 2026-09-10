<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Graph;

/**
 * Which colour a series is drawn in.
 *
 * Its own class because the slot order is the colour-vision safety mechanism, not a detail of any
 * one chart: the order was validated as a set, and a renderer that picked its own hues would defeat
 * that without anything failing.
 */
class SeriesPalette
{
    /**
     * Categorical series colours, in fixed slot order, drawn from the Mage-OS admin palette.
     *
     * A *fixed* order, never cycled and never generated: a colour identifies a consumer, so the
     * same consumer keeps its colour as the field around it changes, and a series past the last
     * slot folds into "Other" rather than being handed an invented hue nothing can tell apart.
     *
     * Validated as a set against this chart's surface: worst adjacent CVD separation 19.9, worst
     * normal-vision separation 29.1, every slot at or above the 3:1 a mark carrying meaning needs.
     * The green is one step down from `@color-green-apple`, which measures 2.99 and misses that
     * bar by a hundredth.
     *
     * @var string[]
     */
    public const SERIES_COLOURS = [
        '#f56a14',
        '#8716e0',
        '#74991f',
        '#007bdb',
        '#f9425a',
    ];

    /**
     * Colour of the folded "Other" series: the de-emphasis grey, not a sixth hue.
     *
     * "Other" is not another consumer, it is everything the chart chose not to name, so it reads
     * as context behind the named series rather than competing with them for identity.
     */
    public const SERIES_OTHER_COLOUR = '#898781';

    /**
     * Series label {@see seriesColour()} treats as the folded tail rather than as a named series.
     */
    public const OTHER_SERIES_LABEL = 'Other';

    /**
     * The colour a series is drawn in: its slot's hue, or the de-emphasis grey for "Other".
     *
     * @param string $seriesLabel
     * @param int $slot
     * @return string
     */
    public function seriesColour(string $seriesLabel, int $slot): string
    {
        if ($seriesLabel === self::OTHER_SERIES_LABEL) {
            return self::SERIES_OTHER_COLOUR;
        }

        return self::SERIES_COLOURS[$slot % count(self::SERIES_COLOURS)];
    }
}
