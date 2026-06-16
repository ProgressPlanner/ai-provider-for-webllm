<?php

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider\Provider;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Availability check for the WebLLM provider.
 *
 * For cloud providers "configured" means a valid API key. WebLLM has no
 * credentials, and whether inference can actually run depends on the visitor's
 * browser (WebGPU support, available memory) — something PHP cannot determine.
 *
 * So this reports the provider as configured by default: it is always available
 * to be *selected*, and real capability is negotiated client-side by the
 * browser runtime before any model is loaded. Sites can still gate the provider
 * off via the `ai_provider_webllm_available` filter.
 *
 * @since 0.1.0
 */
class WebLlmProviderAvailability implements ProviderAvailabilityInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function isConfigured(): bool
    {
        $available = true;

        if (function_exists('apply_filters')) {
            /**
             * Filters whether the WebLLM provider is available.
             *
             * Note this is a server-side switch only. Actual WebGPU capability is
             * detected in the browser; this filter lets a site disable the
             * provider entirely regardless of client support.
             *
             * @since 0.1.0
             *
             * @param bool $available Whether the provider is available. Default true.
             */
            $available = (bool) apply_filters('ai_provider_webllm_available', $available);
        }

        return $available;
    }
}
