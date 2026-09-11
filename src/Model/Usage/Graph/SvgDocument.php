<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Graph;

/**
 * The SVG document every chart is wrapped in, and the one place a string is escaped.
 *
 * Escaping is deliberately a single method on a single class. The finished SVG is emitted into the
 * admin with `@noEscape`, because `Escaper::escapeHtml()`'s attribute allow-list strips the
 * geometry attributes that make an SVG an SVG and hands back an empty box — so nothing downstream
 * re-escapes this markup. That makes {@see escape()} the only line of defence, and a second escaper
 * elsewhere in this namespace would be a second place for it to be forgotten.
 */
class SvgDocument
{
    /**
     * Horizontal margin inside a chart, in SVG user units: none.
     *
     * The chart is drawn flush to its own left edge so its labels and bars line up with the card
     * heading above them. Padding here would be a second, competing inset on top of the card's
     * own, which is what pushed the plot 8px out of the card's text column.
     */
    private const CHART_PADDING_X = 0.0;

    /**
     * Wraps a chart body in the `<svg>` root every chart shares: a `viewBox` sized to the chart,
     * and a `<title>` plus `role`/`aria-label` so the graph is announced to assistive technology
     * instead of being silent, script-free markup with no accessible name.
     *
     * @param string $body
     * @param string $title
     * @param float $width
     * @param float $height
     * @return string
     */
    public function wrapSvg(string $body, string $title, float $width, float $height): string
    {
        $escapedTitle = $this->escapeText($title);

        // `width` and `height` are emitted alongside `viewBox` deliberately. A viewBox on its own
        // makes the SVG scale to whatever width its container happens to be, and every `font-size`
        // below scales with it: in an admin column roughly twice the chart's own width, the labels
        // render at twice their intended size and collide with the bars they belong to. Fixing the
        // intrinsic size keeps the chart drawn at the proportions it was laid out for, and the
        // stylesheet caps it with `max-width` for narrow viewports.
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" viewBox="0 0 %s %s"'
            . ' role="img" aria-label="%s"><title>%s</title>%s</svg>',
            $width,
            $height,
            $width,
            $height,
            $escapedTitle,
            $escapedTitle,
            $body
        );
    }

    /**
     * Rendered in place of a chart with nothing to draw.
     *
     * Shows the dashboard an explicit message rather than an empty box a merchant might mistake
     * for a rendering bug.
     *
     * @param string $title
     * @param float $width
     * @param float $height
     * @return string
     */
    public function renderEmptyState(string $title, float $width, float $height): string
    {
        return $this->wrapSvg(
            sprintf(
                '<text x="%s" y="%s" font-size="%s" fill="%s">No usage recorded</text>',
                self::CHART_PADDING_X,
                $height / 2,
                ChartTheme::LABEL_FONT_SIZE,
                ChartTheme::VALUE_COLOUR
            ),
            $title,
            $width,
            $height
        );
    }

    /**
     * Escapes a string before it is interpolated into SVG markup.
     *
     * `ENT_QUOTES` covers both quote characters, so the same call is safe in a quoted attribute
     * value and not only in an element's text content. `ENT_XML1` (rather than the default
     * `ENT_HTML401`) is what makes this the correct escaper for an XML document instead of
     * `Escaper::escapeHtml()`: the framework escaper runs with `double_encode = false`, so a
     * label containing a literal `&amp;` would come back out as `&` there. Every value this class
     * writes into the SVG goes through this one method; nothing downstream re-escapes the
     * finished document, since `Escaper::escapeHtml()`'s attribute allow-list strips the geometry
     * attributes that make an SVG an SVG.
     *
     * @param string $value
     * @return string
     */
    public function escapeText(string $value): string
    {
        // Escaper::escapeHtml() targets HTML and double-encodes nothing (see the docblock above),
        // so it is the wrong tool for this XML output.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
