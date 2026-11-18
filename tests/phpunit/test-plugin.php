<?php

/**
 * Tests for the plugin bootstrap in plugin.php.
 *
 * @package WordPress\WebLlmAiProvider
 */

declare(strict_types=1);

use WordPress\AiClient\AiClient;
use WordPress\WebLlmAiProvider\Bridge\WebLlmBridge;
use WordPress\WebLlmAiProvider\Provider\WebLlmProvider;

class Test_Plugin extends WP_UnitTestCase
{
    public function test_api_key_sentinel_is_defined(): void
    {
        $this->assertTrue(defined('WEBLLM_API_KEY'));
    }

    public function test_hooks_are_registered(): void
    {
        $this->assertSame(5, has_action('init', 'WordPress\\WebLlmAiProvider\\register_provider'));
        $this->assertSame(10, has_action('init', 'WordPress\\WebLlmAiProvider\\register_admin_page'));
        $this->assertSame(10, has_action('init', [WebLlmBridge::class, 'maybeInstall']));
    }

    public function test_provider_is_registered_with_the_ai_client(): void
    {
        if (!class_exists(AiClient::class)) {
            $this->markTestSkipped('The WordPress AI Client is not available in this WordPress version.');
        }

        $this->assertTrue(AiClient::defaultRegistry()->hasProvider(WebLlmProvider::class));
    }
}
