<?php

/**
 * Uninstall cleanup for AI Provider for WebLLM.
 *
 * Removes the jobs table and all options the plugin creates. Runs in a bare
 * context (plugin classes are not loaded), so names are hardcoded — keep them in
 * sync with Bridge\WebLlmBridge, Models\ModelCatalog, and Worker\WorkerController.
 *
 * @package WordPress\WebLlmAiProvider
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$table = $wpdb->prefix . 'ai_webllm_jobs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS {$table}");

$options = [
    'ai_provider_webllm_db_version',
    'ai_provider_webllm_worker',
    'ai_provider_webllm_model',
    'ai_provider_webllm_worker_enabled',
];

foreach ($options as $option) {
    delete_option($option);
}
