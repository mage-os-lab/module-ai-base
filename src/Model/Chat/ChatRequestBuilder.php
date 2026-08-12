<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\ChatRequestBuilderInterface;
use MageOS\AiBase\Api\Data\ChatMessageInterface;
use MageOS\AiBase\Api\Data\ChatMessageInterfaceFactory;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterfaceFactory;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\ToolCallInterface;
use MageOS\AiBase\Api\Data\ToolDefinitionInterface;
use MageOS\AiBase\Api\Data\ToolDefinitionInterfaceFactory;

/**
 * Builds requests through the Api\Data factories, so a store that preferenced its own message or
 * request implementation gets that one here too.
 */
class ChatRequestBuilder implements ChatRequestBuilderInterface
{
    /**
     * @param ChatRequestInterfaceFactory $requestFactory
     * @param ChatMessageInterfaceFactory $messageFactory
     * @param ToolDefinitionInterfaceFactory $toolFactory
     * @param ChatMessageInterface[] $messages Conversation collected so far
     * @param ToolDefinitionInterface[] $tools Tools offered so far
     */
    public function __construct(
        private readonly ChatRequestInterfaceFactory $requestFactory,
        private readonly ChatMessageInterfaceFactory $messageFactory,
        private readonly ToolDefinitionInterfaceFactory $toolFactory,
        private readonly array $messages = [],
        private readonly array $tools = [],
    ) {
    }

    /**
     * @inheritdoc
     */
    public function withSystemMessage(string $content): ChatRequestBuilderInterface
    {
        return $this->withMessage(MessageRole::System, $content);
    }

    /**
     * @inheritdoc
     */
    public function withUserMessage(string $content): ChatRequestBuilderInterface
    {
        return $this->withMessage(MessageRole::User, $content);
    }

    /**
     * @inheritdoc
     */
    public function withAssistantMessage(string $content = '', array $toolCalls = []): ChatRequestBuilderInterface
    {
        return $this->withMessage(MessageRole::Assistant, $content, $toolCalls);
    }

    /**
     * @inheritdoc
     */
    public function withAssistantTurn(ChatResponseInterface $response): ChatRequestBuilderInterface
    {
        return $this->withAssistantMessage($response->getText(), $response->getToolCalls());
    }

    /**
     * @inheritdoc
     */
    public function withToolResult(ToolCallInterface $toolCall, string $result): ChatRequestBuilderInterface
    {
        return $this->withMessage(MessageRole::Tool, $result, [], $toolCall);
    }

    /**
     * @inheritdoc
     */
    public function withTool(string $name, string $description, array $parameters = []): ChatRequestBuilderInterface
    {
        return $this->withToolDefinition($this->toolFactory->create($parameters === []
            ? ['name' => $name, 'description' => $description]
            : ['name' => $name, 'description' => $description, 'parameters' => $parameters]));
    }

    /**
     * @inheritdoc
     */
    public function withToolDefinition(ToolDefinitionInterface $tool): ChatRequestBuilderInterface
    {
        return $this->withState($this->messages, [...$this->tools, $tool]);
    }

    /**
     * @inheritdoc
     */
    public function build(): ChatRequestInterface
    {
        return $this->requestFactory->create([
            'messages' => $this->messages,
            'tools' => $this->tools,
        ]);
    }

    /**
     * Copy with one more message of the given role appended.
     *
     * @param MessageRole $role
     * @param string $content
     * @param ToolCallInterface[] $toolCalls
     * @param ToolCallInterface|null $answeredToolCall
     * @return ChatRequestBuilderInterface
     */
    private function withMessage(
        MessageRole $role,
        string $content,
        array $toolCalls = [],
        ?ToolCallInterface $answeredToolCall = null,
    ): ChatRequestBuilderInterface {
        $message = $this->messageFactory->create([
            'role' => $role,
            'content' => $content,
            'toolCalls' => $toolCalls,
            'answeredToolCall' => $answeredToolCall,
        ]);

        return $this->withState([...$this->messages, $message], $this->tools);
    }

    /**
     * A builder carrying the same collaborators and the given conversation.
     *
     * @param ChatMessageInterface[] $messages
     * @param ToolDefinitionInterface[] $tools
     * @return ChatRequestBuilderInterface
     */
    private function withState(array $messages, array $tools): ChatRequestBuilderInterface
    {
        return new self(
            $this->requestFactory,
            $this->messageFactory,
            $this->toolFactory,
            $messages,
            $tools,
        );
    }
}
