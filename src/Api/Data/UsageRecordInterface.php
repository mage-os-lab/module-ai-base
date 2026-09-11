<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * One recorded AI call, as the rest of the module sees it.
 *
 * The recording decorator builds one of these from a {@see ChatResponseInterface} and the request
 * it answered, before anything has been persisted. `Model\Usage\Repository` (task 005) is what
 * turns a saved row back into one of these and what assigns the id, which is why every accessor
 * that a stored row always has is nonetheless read here from immutable constructor state rather
 * than from a database round-trip: a record that has not been saved yet is a legitimate value of
 * this type, not a special case.
 */
interface UsageRecordInterface
{
    /**
     * Consumer stored for a call nothing attributed itself to.
     *
     * The consumer column is not-null, so an unattributed call still needs a value to store. This
     * constant is what the grid, the stats layer and the decorator all read and write instead of
     * each spelling the literal `unknown` themselves, which would only need to drift once for a
     * "consumer" filter to silently stop matching some of the rows it should.
     */
    public const CONSUMER_UNKNOWN = 'unknown';

    /**
     * Primary key of the stored row, or null before it has been saved.
     *
     * The recording decorator builds a record to hand to the repository before any insert has
     * happened, and pretending it already has an id would mean inventing one nobody assigned.
     *
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * Row key of the {@see AiServiceInterface} the call was made through.
     *
     * This is {@see AiServiceInterface::getId()}, an opaque JSON object key rather than a
     * database id, which is why it is a string even though {@see getId()} on this interface is
     * an int.
     *
     * @return string
     */
    public function getServiceId(): string;

    /**
     * Machine code of the AI backend that served the call.
     *
     * Denormalised onto the row rather than looked up through {@see getServiceId()} at read time,
     * because a service row can be deleted or reconfigured long after the call it served is still
     * being reported on.
     *
     * @return string
     */
    public function getServiceCode(): string;

    /**
     * Model name the provider reported for the call.
     *
     * @return string
     */
    public function getModel(): string;

    /**
     * Identifier of the module or skill that made the call.
     *
     * Never null: a call nothing attributed itself to is stored with {@see CONSUMER_UNKNOWN}
     * rather than a missing value, so grouping and filtering by consumer never has to special-case
     * absence.
     *
     * @return string
     */
    public function getConsumer(): string;

    /**
     * Store the call ran under.
     *
     * Never null: the admin store is 0, which is what cron and CLI report when no storefront
     * scope is in play, rather than a null that would need its own handling everywhere a caller
     * groups or filters by store.
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Prompt tokens the call consumed.
     *
     * A stored row always has a count, defaulting to 0 when the provider reported none, so this
     * stays a plain int rather than mirroring the nullable {@see TokenUsageInterface::getPromptTokens()}
     * it was built from.
     *
     * @return int
     */
    public function getInputTokens(): int;

    /**
     * Completion tokens the call produced.
     *
     * @return int
     */
    public function getOutputTokens(): int;

    /**
     * Total tokens for the call, provider-reported or prompt plus completion.
     *
     * @return int
     */
    public function getTotalTokens(): int;

    /**
     * Cached portion of the prompt tokens, when the provider reported prompt caching separately.
     *
     * Stays nullable, unlike the three counts above: a stored row has no default cache count to
     * fall back to the way it falls back to 0 for tokens actually spent, and treating "not
     * reported" the same as "reported as zero" would misreport providers that support caching but
     * happened not to hit it on this call the same as providers that cannot report it at all.
     *
     * @return int|null
     */
    public function getCachedTokens(): ?int;

    /**
     * Reasoning portion of the completion tokens, when the provider reported thinking tokens separately.
     *
     * @return int|null
     */
    public function getReasoningTokens(): ?int;

    /**
     * Whether the call was served over a streaming response.
     *
     * @return bool
     */
    public function isStreamed(): bool;

    /**
     * Timestamp the database assigned when the row was written, or null before it has been saved.
     *
     * The `created_at` column is written by the database ({@see \MageOS\AiBase\Model\Usage\Repository},
     * task 005), not chosen by the caller, so a record built ahead of that insert has no value to
     * report yet.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;
}
