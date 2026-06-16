<?php

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\WebLlmAiProvider\Metadata\WebLlmModelMetadataDirectory;
use WordPress\WebLlmAiProvider\Models\WebLlmTextGenerationModel;

/**
 * Class for the WebLLM provider.
 *
 * WebLLM is a client-side provider: models run in the browser via WebGPU. This
 * class extends {@see AbstractProvider} (not the API-based provider) because
 * there is no remote endpoint for PHP to call — inference happens in the
 * browser runtime. The provider exists so the model catalogue, metadata, and
 * availability are discoverable by the AI Client and the Connectors UI.
 *
 * @since 0.1.0
 */
class WebLlmProvider extends AbstractProvider
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        // Every WebLLM model in the catalogue is a text generation model.
        return new WebLlmTextGenerationModel($modelMetadata, $providerMetadata);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $providerMetadataArgs = [
            'webllm',
            'WebLLM (in-browser)',
            // A client-side provider: the model runs in the user's browser, not the cloud or a server.
            ProviderTypeEnum::client(),
            // No external credentials page: in-browser inference needs no real API key.
            null,
            /*
             * Declare an API-key auth method even though WebLLM needs no key. Core's Connectors
             * screen only surfaces (and lets users activate/configure) connectors whose method is
             * `api_key`; a keyless `none` connector is registered but never rendered. The key itself
             * is a no-op — the plugin hardcodes the WEBLLM_API_KEY constant to "not-needed" so the
             * user is never prompted for one. Surfacing the connector is what lets users pick a model.
             */
            RequestAuthenticationMethod::apiKey(),
        ];

        // Provider description support was added in 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            if (function_exists('__')) {
                $providerMetadataArgs[] = __(
                    'Private, no-cost text generation with a model that runs entirely in the browser.',
                    'ai-provider-for-webllm'
                );
            } else {
                $providerMetadataArgs[] = 'Private, no-cost text generation with a model that runs entirely in the browser.';
            }
        }

        // Provider logoPath support was added in 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $providerMetadataArgs[] = dirname(__DIR__, 2) . '/assets/images/webllm.svg';
        }

        return new ProviderMetadata(...$providerMetadataArgs);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new WebLlmProviderAvailability();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new WebLlmModelMetadataDirectory();
    }
}
