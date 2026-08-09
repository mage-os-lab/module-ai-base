<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * Kind of event a streamed chat yields.
 *
 * Thinking is separate from text because it is the model's reasoning rather than its answer, and
 * a consumer rendering it into a chat window has to be able to style or suppress it.
 */
enum StreamChunkType: string
{
    case Text = 'text';
    case Thinking = 'thinking';
    case ToolCall = 'tool_call';
    case Usage = 'usage';
}
