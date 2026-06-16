<?php

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider\Models;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\WebLlmAiProvider\Bridge\WebLlmBridge;

/**
 * A WebLLM text generation model.
 *
 * WebLLM runs in the browser, so a PHP generation call is bridged to a connected
 * browser worker (see {@see WebLlmBridge}): the prompt is serialised to
 * OpenAI-shaped chat-completions params (which WebLLM consumes natively), the
 * worker runs inference and returns the completion, and that is parsed back into a
 * {@see GenerativeAiResult}. If no worker is connected, the bridge throws.
 *
 * @since 0.1.0
 *
 * @phpstan-type CompletionData array{
 *     id?: string,
 *     choices?: list<array{message?: array{content?: string}, finish_reason?: string}>,
 *     usage?: array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}
 * }
 */
class WebLlmTextGenerationModel implements ModelInterface, TextGenerationModelInterface
{
    /**
     * @var ModelMetadata The metadata for the model.
     */
    private ModelMetadata $metadata;

    /**
     * @var ProviderMetadata The metadata for the model's provider.
     */
    private ProviderMetadata $providerMetadata;

    /**
     * @var ModelConfig The configuration for the model.
     */
    private ModelConfig $config;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param ModelMetadata    $metadata         The metadata for the model.
     * @param ProviderMetadata $providerMetadata The metadata for the model's provider.
     */
    public function __construct(ModelMetadata $metadata, ProviderMetadata $providerMetadata)
    {
        $this->metadata         = $metadata;
        $this->providerMetadata = $providerMetadata;
        $this->config           = ModelConfig::fromArray([]);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function metadata(): ModelMetadata
    {
        return $this->metadata;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function providerMetadata(): ProviderMetadata
    {
        return $this->providerMetadata;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function setConfig(ModelConfig $config): void
    {
        $this->config = $config;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getConfig(): ModelConfig
    {
        return $this->config;
    }

    /**
     * {@inheritDoc}
     *
     * Bridges the request to a browser worker running WebLLM.
     *
     * @since 0.1.0
     *
     * @throws \RuntimeException If no worker is connected, or generation fails/times out.
     */
    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $payload = $this->buildPayload($prompt);

        /**
         * Filters the maximum seconds PHP waits for a worker to return a result.
         *
         * @since 0.3.0
         *
         * @param int $timeout Timeout in seconds. Default 120.
         */
        $timeout = (int) apply_filters('ai_provider_webllm_timeout', 120);

        $completion = WebLlmBridge::run($payload, $timeout);

        return $this->parseCompletion($completion);
    }

    /**
     * Serialises the prompt and config into OpenAI-shaped chat-completions params.
     *
     * @since 0.3.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, mixed> Params WebLLM's `chat.completions.create()` accepts.
     */
    private function buildPayload(array $prompt): array
    {
        $config   = $this->config;
        $messages = [];

        $system = $config->getSystemInstruction();
        if ($system) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($prompt as $message) {
            $role = $message->getRole() === MessageRoleEnum::model() ? 'assistant' : 'user';
            $text = '';
            foreach ($message->getParts() as $part) {
                if ($part->getType()->isText()) {
                    $text .= (string) $part->getText();
                }
            }
            $messages[] = ['role' => $role, 'content' => $text];
        }

        $payload = [
            'model'    => $this->metadata->getId(),
            'messages' => $messages,
        ];

        $temperature = $config->getTemperature();
        if (null !== $temperature) {
            $payload['temperature'] = $temperature;
        }

        $maxTokens = $config->getMaxTokens();
        if (null !== $maxTokens) {
            $payload['max_tokens'] = $maxTokens;
        }

        $topP = $config->getTopP();
        if (null !== $topP) {
            $payload['top_p'] = $topP;
        }

        $stop = $config->getStopSequences();
        if (is_array($stop)) {
            $payload['stop'] = $stop;
        }

        if ('application/json' === $config->getOutputMimeType()) {
            $schema = $config->getOutputSchema();
            $payload['response_format'] = is_array($schema)
                ? ['type' => 'json_object', 'schema' => $schema]
                : ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * Parses an OpenAI-shaped completion into a generative AI result.
     *
     * @since 0.3.0
     *
     * @param array<string, mixed> $data The completion returned by the worker.
     * @return GenerativeAiResult The parsed result.
     */
    private function parseCompletion(array $data): GenerativeAiResult
    {
        /** @var CompletionData $data */
        $choices = isset($data['choices']) && is_array($data['choices']) ? $data['choices'] : [];
        $first   = $choices[0] ?? [];

        $content = '';
        if (isset($first['message']['content']) && is_string($first['message']['content'])) {
            $content = $first['message']['content'];
        }

        $finishReason = $this->mapFinishReason(
            isset($first['finish_reason']) && is_string($first['finish_reason']) ? $first['finish_reason'] : 'stop'
        );

        $candidate = new Candidate(
            new Message(MessageRoleEnum::model(), [new MessagePart($content)]),
            $finishReason
        );

        $usage      = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $tokenUsage = new TokenUsage(
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            (int) ($usage['total_tokens'] ?? 0)
        );

        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';

        return new GenerativeAiResult(
            $id,
            [$candidate],
            $tokenUsage,
            $this->providerMetadata,
            $this->metadata,
            []
        );
    }

    /**
     * Maps an OpenAI finish_reason to a FinishReasonEnum.
     *
     * @since 0.3.0
     *
     * @param string $reason The OpenAI finish reason.
     * @return FinishReasonEnum The mapped finish reason.
     */
    private function mapFinishReason(string $reason): FinishReasonEnum
    {
        switch ($reason) {
            case 'length':
                return FinishReasonEnum::length();
            case 'content_filter':
                return FinishReasonEnum::contentFilter();
            case 'tool_calls':
                return FinishReasonEnum::toolCalls();
            default:
                return FinishReasonEnum::stop();
        }
    }
}
