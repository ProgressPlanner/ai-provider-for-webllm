<?php

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider\Worker;

use WordPress\WebLlmAiProvider\Bridge\RestController;
use WordPress\WebLlmAiProvider\Models\ModelCatalog;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires up the browser worker: enqueues the client loop on admin pages (when
 * enabled) so a connected browser can run jobs the PHP bridge enqueues.
 *
 * The engine runs in a dedicated Web Worker (WebLLM's WebWorkerMLCEngine), which
 * is a plain same-origin module script — no service worker, scope, or special
 * serving required.
 *
 * @since 0.3.0
 */
class WorkerController
{
    public const OPTION_ENABLED = 'ai_provider_webllm_worker_enabled';
    public const HANDLE         = 'ai-provider-webllm-worker';

    /**
     * Registers hooks.
     *
     * @since 0.3.0
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    /**
     * Whether the in-browser worker is enabled.
     *
     * @since 0.3.0
     *
     * @return bool True if enabled.
     */
    public static function isEnabled(): bool
    {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    /**
     * Enqueues the worker client on admin pages when enabled.
     *
     * Runs on every admin page so the worker is available as the admin navigates.
     *
     * @since 0.3.0
     *
     * @return void
     */
    public static function enqueue(): void
    {
        if (!self::isEnabled() || !current_user_can(RestController::capability())) {
            return;
        }

        $mainFile = dirname(__DIR__, 2) . '/plugin.php';

        wp_enqueue_script(
            self::HANDLE,
            plugins_url('assets/js/webllm-worker.js', $mainFile),
            ['wp-i18n'],
            '0.1.0',
            true
        );

        wp_set_script_translations(self::HANDLE, 'ai-provider-for-webllm');

        wp_localize_script(
            self::HANDLE,
            'aiProviderWebllmWorker',
            [
                'enabled'   => true,
                'model'     => ModelCatalog::selectedId(),
                'restUrl'   => esc_url_raw(rest_url(RestController::NAMESPACE)),
                'nonce'     => wp_create_nonce('wp_rest'),
                'workerUrl' => esc_url_raw(plugins_url('assets/js/webllm-engine-worker.js', $mainFile)),
            ]
        );
    }
}
