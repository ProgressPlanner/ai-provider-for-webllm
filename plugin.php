<?php

/**
 * Plugin Name: AI Provider for WebLLM
 * Plugin URI: https://github.com/jdevalk/ai-provider-for-webllm
 * Description: Registers an in-browser WebLLM model as a client-side AI Provider for the WordPress AI Client.
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Version: 0.1.0
 * Author: Joost de Valk
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-webllm
 *
 * @package WordPress\WebLlmAiProvider
 */

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\WebLlmAiProvider\Bridge\RestController;
use WordPress\WebLlmAiProvider\Bridge\WebLlmBridge;
use WordPress\WebLlmAiProvider\Provider\WebLlmProvider;
use WordPress\WebLlmAiProvider\Settings\AdminPage;
use WordPress\WebLlmAiProvider\Worker\WorkerController;

if (!defined('ABSPATH')) {
    return;
}

/*
 * WebLLM runs entirely in the browser and needs no API key. But core's Connectors
 * screen only surfaces connectors that authenticate with an `api_key`, so the provider
 * declares that method (see WebLlmProvider). To keep users from ever being asked for a
 * key, we hardcode the key constant — core derives the name `WEBLLM_API_KEY` from the
 * provider id `webllm` — to a sentinel value, so the credential is always "supplied".
 */
if (!defined('WEBLLM_API_KEY')) {
    define('WEBLLM_API_KEY', 'not-needed');
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the AI Provider for WebLLM with the AI Client.
 *
 * Unlike the cloud providers, WebLLM is a client-side (browser) provider: the
 * model runs in the visitor's browser via WebGPU. The PHP classes registered
 * here describe the provider and its models so the AI Client and the
 * Settings > Connectors UI recognise it; the actual inference is driven from
 * the browser runtime (see assets/js), never from PHP. Server-initiated
 * generation therefore fails loudly — there is no browser in the loop.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(WebLlmProvider::class)) {
        return;
    }

    $registry->registerProvider(WebLlmProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);

/**
 * Registers the Settings > WebLLM admin page (model selection).
 *
 * @since 0.2.0
 *
 * @return void
 */
function register_admin_page(): void
{
    AdminPage::register();
}

add_action('init', __NAMESPACE__ . '\\register_admin_page');

// The browser-worker bridge: REST endpoints + the jobs table that lets PHP-initiated
// generation run in a connected browser (see Bridge\WebLlmBridge).
RestController::register();
WorkerController::register();
add_action('init', [WebLlmBridge::class, 'maybeInstall']);
register_activation_hook(__FILE__, [WebLlmBridge::class, 'maybeInstall']);
