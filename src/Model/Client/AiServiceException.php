<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Exception\LocalizedException;

/**
 * A call reached the provider and failed there, for a reason symfony/ai did not report as one of
 * the more specific failures below.
 *
 * Base of the typed hierarchy {@see AiExceptionMapper} builds: catching this one catches every
 * provider failure, the same way catching {@see LocalizedException} always has, while a consumer
 * that wants to tell one failure from another catches a subclass instead. Unlike
 * {@see AiRequestNotSentException}, an exception of this type (or any subclass) means the request
 * was sent and, on most providers, billed.
 */
class AiServiceException extends LocalizedException
{
}
