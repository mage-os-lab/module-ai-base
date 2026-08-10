<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\ToolCallInterface;
use MageOS\AiBase\Api\Data\ToolDefinitionInterface;

/**
 * Assembles a ChatRequestInterface without naming a single implementation class.
 *
 * The request and its parts are value objects with positional constructors, and a tool-result turn
 * is four arguments deep with two of them usually empty. Consumers reaching for `new` there are
 * bound to concrete Model classes and to argument order; this builder is the supported way to get
 * a request, and it reads as the conversation it describes.
 *
 * Inject the generated factory and start a request per call:
 *
 *     public function __construct(
 *         private readonly ChatRequestBuilderInterfaceFactory $chatRequestBuilderFactory,
 *     ) {}
 *
 *     $request = $this->chatRequestBuilderFactory->create()
 *         ->withSystemMessage('You are a Magento support assistant.')
 *         ->withUserMessage($question)
 *         ->withTool('get_orders', 'Lists orders by status', $schema)
 *         ->build();
 *
 * Every method returns a new builder rather than mutating this one, so a builder holding the
 * common preamble can be kept and branched from safely.
 */
interface ChatRequestBuilderInterface
{
    /**
     * Copy with a system instruction appended.
     *
     * @param string $content
     * @return ChatRequestBuilderInterface
     */
    public function withSystemMessage(string $content): ChatRequestBuilderInterface;

    /**
     * Copy with a user message appended.
     *
     * @param string $content
     * @return ChatRequestBuilderInterface
     */
    public function withUserMessage(string $content): ChatRequestBuilderInterface;

    /**
     * Copy with an assistant message appended.
     *
     * @param string $content
     * @param ToolCallInterface[] $toolCalls Calls the assistant requested in that turn
     * @return ChatRequestBuilderInterface
     */
    public function withAssistantMessage(string $content = '', array $toolCalls = []): ChatRequestBuilderInterface;

    /**
     * Copy with the model's own turn appended, text and requested tool calls together.
     *
     * @param ChatResponseInterface $response
     * @return ChatRequestBuilderInterface
     */
    public function withAssistantTurn(ChatResponseInterface $response): ChatRequestBuilderInterface;

    /**
     * Copy with a tool's result appended, bound to the call that produced it.
     *
     * @param ToolCallInterface $toolCall
     * @param string $result
     * @return ChatRequestBuilderInterface
     */
    public function withToolResult(ToolCallInterface $toolCall, string $result): ChatRequestBuilderInterface;

    /**
     * Copy offering one more tool to the model.
     *
     * The schema reaches the tool definition through a Magento-generated factory, and the
     * ObjectManager walks array arguments looking for DI references: a nested object carrying an
     * `instance` key is resolved as a service, and one carrying an `argument` key is replaced by a
     * global argument. So a tool argument may not be *named* `instance` or `argument` — the first
     * raises a TypeError, the second silently nulls the surrounding object. Every other name,
     * including every nested level, passes through untouched. Rename the argument, or build the
     * definition yourself and pass it to withToolDefinition(), which does not go through a factory.
     *
     * @param string $name Name the model calls the tool by
     * @param string $description What the tool does, written for the model
     * @param array<string,mixed> $parameters JSON Schema of the tool's arguments; no argument may be named
     *        `instance` or `argument`
     * @return ChatRequestBuilderInterface
     */
    public function withTool(string $name, string $description, array $parameters = []): ChatRequestBuilderInterface;

    /**
     * Copy offering one more already-described tool, for consumers with their own tool registry.
     *
     * @param ToolDefinitionInterface $tool
     * @return ChatRequestBuilderInterface
     */
    public function withToolDefinition(ToolDefinitionInterface $tool): ChatRequestBuilderInterface;

    /**
     * The assembled request.
     *
     * @return ChatRequestInterface
     * @throws \InvalidArgumentException When no message was added: a request needs at least one
     */
    public function build(): ChatRequestInterface;
}
