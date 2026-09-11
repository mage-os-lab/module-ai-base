<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Graph;

/**
 * The visual tokens every chart in this namespace draws with.
 *
 * Constants only, in one place, because the same ink and the same type sizes have to hold across
 * the bar charts and the trend: a colour defined twice is a colour that drifts. The values are
 * Mage-OS admin tokens; see each constant for which one and, where it matters, for the measurement
 * behind the choice.
 */
class ChartTheme
{
    /**
     * Font size of a bar's label, in SVG user units. Stated explicitly rather than inherited: an
     * SVG text element with no font-size falls back to the user agent's default of 16px, which is
     * larger than this chart's rows were laid out for.
     */
    public const LABEL_FONT_SIZE = 12.0;

    /**
     * Font size of the value drawn after a bar, in SVG user units. A step below
     * {@see LABEL_FONT_SIZE} so the label reads as the heading of its row and the value as its
     * annotation.
     */
    public const VALUE_FONT_SIZE = 11.0;

    /**
     * Fill of a bar: Mage-OS `@color-mageos-orange-dark`, the brand orange one step down from
     * `@color-mageos-orange`.
     *
     * The brand's primary orange (`#f37121`) measures 2.92:1 against this chart's white surface,
     * just under the 3:1 a mark carrying meaning has to clear, so this takes the next step on the
     * same brand ramp (4.02:1) rather than shipping the lighter one behind a relief rule. Stated
     * here rather than inherited through `currentColor`, because the SVG is emitted into a page
     * whose text colour is near black and a chart of black bars reads as redaction, not as data.
     */
    public const BAR_COLOUR = '#f56a14';

    /**
     * Fill of a bar's label: Mage-OS `@color-mageos-dark`, the admin's primary ink.
     *
     * Text never wears the data colour — identity comes from the mark beside it — so both text
     * fills below are ink tokens rather than steps of the bar's own ramp.
     */
    public const LABEL_COLOUR = '#1a202c';

    /**
     * Fill of the value drawn after a bar: Mage-OS `@color-mageos-gray`, the admin's secondary
     * ink. Recessive against {@see LABEL_COLOUR} while still clearing 4.5:1 for text, which
     * `@color-mageos-gray-light` (3.9:1) would not at this size.
     */
    public const VALUE_COLOUR = '#4a5568';

    /**
     * The hairline every bar grows from: Mage-OS `@color-mageos-stroke`, the same token the
     * admin draws its own card and table rules in. Solid rather than dashed — a dashed rule reads
     * as a threshold or a projection, and this is neither.
     */
    public const BASELINE_COLOUR = '#dde0e5';

    /**
     * The card colour a marker's ring is drawn in, so the ring reads as a gap rather than as a
     * stroke around the mark. Matches `@mageos-ai-usage-surface` in the module's stylesheet.
     */
    public const SURFACE_COLOUR = '#ffffff';
}
