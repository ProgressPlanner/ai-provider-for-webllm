<?php

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider\Models;

/**
 * Stores the user's selected WebLLM model.
 *
 * There is deliberately no model list in PHP. The authoritative, always-current
 * catalogue is WebLLM's own `prebuiltAppConfig.model_list`, which the Settings >
 * WebLLM picker reads in the browser. PHP only persists the chosen `model_id`
 * string; this class is the small helper around that option, plus a sensible
 * real default for before the user has chosen.
 *
 * @since 0.2.0
 */
class ModelCatalog
{
    /**
     * Option name storing the user's selected model ID.
     *
     * @since 0.2.0
     *
     * @var string
     */
    public const OPTION = 'ai_provider_webllm_model';

    /**
     * Default model when the user has not chosen one.
     *
     * A current, modestly sized model so first use is not a huge download. This is
     * the one place a model ID is hardcoded — only as a fallback default, not a
     * maintained catalogue. It must be a valid WebLLM `prebuiltAppConfig` model ID.
     *
     * @since 0.2.0
     *
     * @var string
     */
    public const DEFAULT_MODEL = 'Qwen3.5-4B-q4f16_1-MLC';

    /**
     * Returns the user-selected model ID, or the default when unset.
     *
     * @since 0.2.0
     *
     * @return string The selected WebLLM model ID.
     */
    public static function selectedId(): string
    {
        $selected = function_exists('get_option') ? (string) get_option(self::OPTION, '') : '';

        return '' !== $selected ? $selected : self::DEFAULT_MODEL;
    }
}
