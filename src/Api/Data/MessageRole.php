<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * Author of a message in a conversation.
 *
 * The four roles every supported provider models, whatever it calls them on the wire. Tool
 * results are their own role rather than a user message, because a provider needs to pair them
 * with the call that produced them.
 */
enum MessageRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
