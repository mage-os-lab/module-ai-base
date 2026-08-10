<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

/**
 * Escape hatch to the symfony/ai-platform instance behind a client.
 *
 * `AiClientInterface` covers chat, streaming and single-turn completion. symfony/ai-platform can do
 * a great deal more (agents with executed tool loops via symfony/ai-agent, message stores via
 * symfony/ai-chat, structured output, embeddings, vector stores, image and audio), and mirroring
 * all of it here would mean re-describing an API that already exists. A consumer that wants those
 * takes the platform this module already built for it, credentials resolved and bridge selected,
 * and talks to Symfony directly.
 *
 * **Everything reached through this interface is outside this module's compatibility promise.**
 * symfony/ai-platform is an experimental Symfony component: experimental features are not covered
 * by Symfony's Backward Compatibility Promise, and it breaks in practice on the surface a tool loop
 * touches most. `Message::ofAssistant()` was reworked in 0.9 and `Message::ofToolCall()` in 0.11.
 * Code written against `AiClientInterface` is insulated from that by this module; code written
 * against the platform is not, and has to be re-verified whenever symfony/ai-platform is upgraded.
 *
 * Implemented as a separate interface rather than as methods on `AiClientInterface` so the coupling
 * is a deliberate `instanceof`, and so a store that preferences its own client stack simply does
 * not implement it:
 *
 *     $client = $this->aiClientFactory->createById($serviceId);
 *     if (!$client instanceof PlatformAwareInterface) {
 *         return $client->complete($prompt);   // no platform to reach; use the stable surface
 *     }
 *
 *     $agent = new Agent($client->getPlatform(), $client->getModel(), $toolProcessors);
 */
interface PlatformAwareInterface
{
    /**
     * The configured platform, ready to invoke.
     *
     * Typed as `object` rather than as the Symfony interface on purpose: symfony/ai-platform is a
     * soft dependency, and this module has to stay loadable, compilable and usable for
     * configuration storage on an install that never installed it.
     *
     * Pair it with `AiClientInterface::getModel()`, which is the model name to invoke it with.
     *
     * @return object A \Symfony\AI\Platform\PlatformInterface
     */
    public function getPlatform(): object;

    /**
     * Rewrite the universal options for this client's provider, as chat() does internally.
     *
     * Symfony's platform is unified in shape but not in vocabulary: options reach the provider's
     * request body nearly untouched, so `max_tokens` is `max_output_tokens` on OpenAI's and Azure's
     * Responses API, `maxOutputTokens` on Google and `num_predict` on Ollama, Anthropic rejects a
     * request that omits it, and OpenAI-compatible endpoints reject unknown body fields outright.
     * Calling the platform directly means opting out of every translation this module does, so this
     * is offered separately: it is the one piece of the client layer worth keeping when the rest is
     * bypassed.
     *
     *     $result = $client->getPlatform()->invoke(
     *         $client->getModel(),
     *         $messageBag,
     *         $client->normalizeOptions(['max_tokens' => 400]),
     *     );
     *
     * @param array $options Provider-neutral options (max_tokens, temperature, top_p, stop)
     * @return array The same options under the names this client's provider expects
     * @throws \Magento\Framework\Exception\LocalizedException When an option has no equivalent
     *         at this provider
     */
    public function normalizeOptions(array $options): array;
}
