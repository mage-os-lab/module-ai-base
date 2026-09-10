<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Graph;

/**
 * One labelled value handed to {@see SvgRenderer}.
 *
 * Deliberately ignorant of what the label or the value represents: the renderer draws bars and
 * trend lines from labels and numbers only, so a consumer name, a service code and a calendar day
 * are all the same shape to it. The caller (the dashboard block) is the only place that knows
 * what a token is.
 */
class DataPoint
{
    /**
     * @param string $label Untrusted text (a consumer identifier or a model name); the renderer,
     *                       not this class, is responsible for escaping it before it reaches markup.
     * @param float $value The magnitude the label maps to. `float` rather than `int` so a future
     *                      caller charting cost (a currency amount) needs no second value object.
     */
    public function __construct(
        private readonly string $label,
        private readonly float $value,
    ) {
    }

    /**
     * The raw, unescaped label. Callers that render markup must go through
     * {@see SvgRenderer}, which escapes it; nothing else in this pair does.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * The raw magnitude, unrounded and unformatted; {@see SvgRenderer} decides how to display it.
     *
     * @return float
     */
    public function getValue(): float
    {
        return $this->value;
    }
}
