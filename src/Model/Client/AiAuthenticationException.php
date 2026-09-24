<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

/**
 * The provider rejected the configured credentials: an expired key, a revoked one, or one that
 * was never valid.
 *
 * Retrying the identical request only repeats the rejection; an administrator has to fix the
 * configured service before another call can succeed. {@see AiExceptionMapper} builds this from
 * symfony/ai's `AuthenticationException`, which every bridge routed through
 * `HttpStatusErrorHandlingTrait` reports on a 401.
 */
class AiAuthenticationException extends AiServiceException
{
}
