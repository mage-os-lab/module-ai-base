<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * Why the model stopped generating, normalized across providers.
 *
 * Providers spell this differently for the same event: an output-token truncation is `length` at
 * OpenAI, `max_tokens` at Anthropic and `MAX_TOKENS` at Google. A consumer branching on the raw
 * wording would have to know all three, which is the opposite of what a provider-agnostic client
 * is for, so the raw value stays available through ChatResponseInterface::getRawFinishReason()
 * and this enum carries the meaning.
 */
enum FinishReason: string
{
    /**
     * The model finished on its own.
     */
    case Stop = 'stop';

    /**
     * Output was truncated by the token limit, so the text is incomplete.
     */
    case Length = 'length';

    /**
     * The model stopped to request one or more tools.
     */
    case ToolCall = 'tool_call';

    /**
     * A safety filter or guardrail stopped generation.
     */
    case ContentFilter = 'content_filter';

    /**
     * The model hit one of the stop sequences the caller supplied.
     */
    case StopSequence = 'stop_sequence';

    /**
     * The provider reported a reason with no normalized equivalent; read the raw value.
     */
    case Other = 'other';
}
