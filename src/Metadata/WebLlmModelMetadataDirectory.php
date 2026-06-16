<?php

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider\Metadata;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\WebLlmAiProvider\Models\ModelCatalog;

/**
 * Model metadata directory for the WebLLM provider.
 *
 * The full catalogue of loadable models lives in WebLLM's `prebuiltAppConfig`
 * (read in the browser by the Settings > WebLLM picker), not in PHP. The user
 * picks one model there; this directory exposes exactly that selected model to
 * the AI Client — it is the only model actually loaded and run in the browser.
 *
 * @since 0.1.0
 */
class WebLlmModelMetadataDirectory implements ModelMetadataDirectoryInterface
{
    /**
     * {@inheritDoc}
     *
     * Advertises the user-selected model, so it is the "first suitable" model the
     * AI Client uses by default.
     *
     * @since 0.1.0
     */
    public function listModelMetadata(): array
    {
        return [$this->build(ModelCatalog::selectedId())];
    }

    /**
     * {@inheritDoc}
     *
     * Any non-empty ID is accepted: the real catalogue is WebLLM's
     * `prebuiltAppConfig` in the browser, which validates whether a model can
     * actually load. PHP cannot know that list, so it does not gate on it.
     *
     * @since 0.1.0
     */
    public function hasModelMetadata(string $modelId): bool
    {
        return '' !== $modelId;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getModelMetadata(string $modelId): ModelMetadata
    {
        if ('' === $modelId) {
            throw new InvalidArgumentException('A WebLLM model ID is required.');
        }

        return $this->build($modelId);
    }

    /**
     * Builds metadata for a WebLLM model ID.
     *
     * @since 0.1.0
     *
     * @param string $modelId The WebLLM model ID.
     * @return ModelMetadata The model metadata.
     */
    private function build(string $modelId): ModelMetadata
    {
        return new ModelMetadata(
            $modelId,
            // PHP does not know WebLLM's display names; the ID is descriptive enough.
            $modelId,
            [
                CapabilityEnum::textGeneration(),
                CapabilityEnum::chatHistory(),
            ],
            $this->supportedOptions()
        );
    }

    /**
     * Returns the configuration options every WebLLM model supports.
     *
     * WebLLM exposes an OpenAI-compatible chat completions API in the browser,
     * so it supports the common generation options plus JSON-schema structured
     * output and function calling.
     *
     * @since 0.1.0
     *
     * @return list<SupportedOption> The supported options.
     */
    private function supportedOptions(): array
    {
        return [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];
    }
}
