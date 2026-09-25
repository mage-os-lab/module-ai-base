<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

/**
 * The provider rejected this specific request: a malformed body, a prompt over its context
 * window, a model name it does not recognize, or, as the {@see AiContentFilteredException}
 * subtype, content its safety filter refused to answer.
 *
 * Retrying unchanged fails again; the request itself, not the account or the provider, has to
 * change. {@see AiExceptionMapper} builds this from symfony/ai's `BadRequestException`,
 * `ExceedContextSizeException` and `ModelNotFoundException`: none of the three are recoverable by
 * waiting, which is what separates them from {@see AiTransientException} and
 * {@see AiRateLimitedException} here.
 */
class AiInvalidRequestException extends AiServiceException
{
}
