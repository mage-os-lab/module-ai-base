<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

/**
 * The provider's safety filter refused to answer this request.
 *
 * A subtype of {@see AiInvalidRequestException} so an existing catch for that keeps working, but
 * worth telling apart from it: a malformed request is a bug to log, a refusal is usually caused by
 * what a customer typed and is something to show them. {@see AiExceptionMapper} builds this from
 * symfony/ai's `ContentFilterException`.
 */
class AiContentFilteredException extends AiInvalidRequestException
{
}
