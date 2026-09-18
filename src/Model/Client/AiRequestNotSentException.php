<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Exception\LocalizedException;

/**
 * A call rejected before it reached the platform: an unsupported option, an unusable model
 * override, or a malformed request the caller built wrong.
 *
 * Nobody paid for it. It is a programming error in the calling code, not something the provider
 * rejected after being billed for the attempt, which is why the recording decorator (see
 * `RecordingAiClient`) skips this exception type rather than logging it as a failed call. It
 * extends {@see LocalizedException} so every existing `catch (LocalizedException)` at a consumer
 * boundary keeps working unchanged.
 */
class AiRequestNotSentException extends LocalizedException
{
}
