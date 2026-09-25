<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

/**
 * The provider failed in a way that is usually temporary: a 5xx response, an overloaded model, a
 * network failure, or a stream that ended before it reported completion.
 *
 * Worth an immediate retry, unlike {@see AiAuthenticationException} or
 * {@see AiInvalidRequestException}, which repeat on the identical request. {@see AiExceptionMapper}
 * builds this from symfony/ai's `ServerException` and `IncompleteStreamException`, and from the
 * HTTP client's `TransportExceptionInterface`.
 */
class AiTransientException extends AiServiceException
{
}
