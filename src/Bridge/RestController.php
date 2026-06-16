<?php

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider\Bridge;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST endpoints the browser worker uses to receive jobs and return results.
 *
 * - POST poll   : record a heartbeat and claim the next pending job (short long-poll).
 * - POST result : report a job's result or error.
 *
 * @since 0.3.0
 */
class RestController
{
    public const NAMESPACE = 'ai-provider-webllm/v1';

    /**
     * Seconds a single poll request waits for a job before returning empty.
     *
     * @var int
     */
    private const POLL_HOLD = 20;

    /**
     * Registers the REST routes.
     *
     * @since 0.3.0
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    /**
     * Defines the routes.
     *
     * @since 0.3.0
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/poll',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handlePoll'],
                'permission_callback' => [self::class, 'permission'],
                'args'                => [
                    'ready' => ['type' => 'boolean', 'default' => false],
                    'model' => ['type' => 'string', 'default' => ''],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/result',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handleResult'],
                'permission_callback' => [self::class, 'permission'],
                'args'                => [
                    'id'          => ['type' => 'integer', 'required' => true],
                    'claim_token' => ['type' => 'string', 'required' => true],
                    'result'      => ['type' => 'object'],
                    'error'       => ['type' => 'string'],
                ],
            ]
        );
    }

    /**
     * Permission check for worker endpoints.
     *
     * @since 0.3.0
     *
     * @return bool Whether the current user may act as a worker.
     */
    public static function permission(): bool
    {
        return current_user_can(self::capability());
    }

    /**
     * Returns the capability required to operate the browser worker.
     *
     * @since 0.3.0
     *
     * @return string The required capability.
     */
    public static function capability(): string
    {
        /**
         * Filters the capability required to operate the WebLLM worker.
         *
         * @since 0.3.0
         *
         * @param string $capability The capability. Default 'manage_options'.
         */
        $capability = (string) apply_filters('ai_provider_webllm_worker_capability', 'manage_options');

        return '' !== $capability ? $capability : 'manage_options';
    }

    /**
     * Records a heartbeat and waits briefly for a job to claim.
     *
     * @since 0.3.0
     *
     * @param WP_REST_Request $request The request.
     * @return WP_REST_Response The claimed job, or null.
     */
    public static function handlePoll(WP_REST_Request $request): WP_REST_Response
    {
        $ready = (bool) $request->get_param('ready');
        $model = sanitize_text_field((string) $request->get_param('model'));

        WebLlmBridge::heartbeat($ready, $model);

        // A worker that is still loading the model cannot run jobs yet.
        if (!$ready) {
            return new WP_REST_Response(['job' => null], 200);
        }

        $deadline = microtime(true) + self::POLL_HOLD;
        do {
            $job = WebLlmBridge::claimNext($model);
            if (null !== $job) {
                return new WP_REST_Response(['job' => $job], 200);
            }
            usleep(500000);
        } while (microtime(true) < $deadline);

        return new WP_REST_Response(['job' => null], 200);
    }

    /**
     * Records a worker's result or error for a job.
     *
     * @since 0.3.0
     *
     * @param WP_REST_Request $request The request.
     * @return WP_REST_Response Acknowledgement.
     */
    public static function handleResult(WP_REST_Request $request): WP_REST_Response
    {
        $id         = (int) $request->get_param('id');
        $claimToken = (string) $request->get_param('claim_token');
        $result     = $request->get_param('result');
        $error      = $request->get_param('error');

        $completed = WebLlmBridge::completeJob(
            $id,
            $claimToken,
            is_array($result) ? $result : null,
            is_string($error) && '' !== $error ? $error : null
        );

        if (!$completed) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid or stale job claim.'], 409);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }
}
