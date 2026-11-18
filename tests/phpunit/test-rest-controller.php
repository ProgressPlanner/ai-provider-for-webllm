<?php

/**
 * Tests for the worker REST endpoints.
 *
 * The /poll endpoint is only tested with ready=false: a ready poll holds the
 * request open for up to 20 seconds waiting for a job.
 *
 * @package WordPress\WebLlmAiProvider
 */

declare(strict_types=1);

use WordPress\WebLlmAiProvider\Bridge\RestController;
use WordPress\WebLlmAiProvider\Bridge\WebLlmBridge;

class Test_Rest_Controller extends WP_UnitTestCase
{
    private const MODEL = 'Test-Model-MLC';

    public function set_up()
    {
        parent::set_up();

        global $wp_rest_server;

        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
    }

    public function tear_down()
    {
        global $wp_rest_server;

        $wp_rest_server = null;

        parent::tear_down();
    }

    /**
     * Dispatches a POST request to one of the worker endpoints.
     *
     * @param string               $route  The route below the namespace, e.g. '/poll'.
     * @param array<string, mixed> $params The body parameters.
     * @return WP_REST_Response The response.
     */
    private function post(string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/' . RestController::NAMESPACE . $route);
        $request->set_body_params($params);

        return rest_get_server()->dispatch($request);
    }

    private function actAsAdmin(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_routes_are_registered(): void
    {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/' . RestController::NAMESPACE . '/poll', $routes);
        $this->assertArrayHasKey('/' . RestController::NAMESPACE . '/heartbeat', $routes);
        $this->assertArrayHasKey('/' . RestController::NAMESPACE . '/result', $routes);
    }

    public function test_capability_defaults_to_manage_options(): void
    {
        $this->assertSame('manage_options', RestController::capability());
    }

    public function test_capability_is_filterable(): void
    {
        add_filter('ai_provider_webllm_worker_capability', static function (): string {
            return 'edit_posts';
        });

        $this->assertSame('edit_posts', RestController::capability());
    }

    public function test_empty_filtered_capability_falls_back_to_manage_options(): void
    {
        add_filter('ai_provider_webllm_worker_capability', '__return_empty_string');

        $this->assertSame('manage_options', RestController::capability());
    }

    public function test_permission_follows_the_capability(): void
    {
        wp_set_current_user(0);
        $this->assertFalse(RestController::permission());

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertFalse(RestController::permission());

        $this->actAsAdmin();
        $this->assertTrue(RestController::permission());
    }

    public function test_endpoints_reject_anonymous_requests(): void
    {
        wp_set_current_user(0);

        $this->assertSame(401, $this->post('/heartbeat')->get_status());
        $this->assertSame(401, $this->post('/poll')->get_status());
        $this->assertSame(401, $this->post('/result', ['id' => 1, 'claim_token' => 'x'])->get_status());
    }

    public function test_endpoints_reject_users_without_the_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertSame(403, $this->post('/heartbeat')->get_status());
    }

    public function test_heartbeat_marks_the_worker_available(): void
    {
        $this->actAsAdmin();

        $response = $this->post('/heartbeat', ['ready' => true, 'model' => self::MODEL]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['ok' => true], $response->get_data());
        $this->assertTrue(WebLlmBridge::workerAvailable(self::MODEL));
    }

    public function test_poll_from_a_loading_worker_returns_no_job(): void
    {
        $this->actAsAdmin();

        $response = $this->post('/poll', ['ready' => false, 'model' => self::MODEL]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['job' => null], $response->get_data());
        $this->assertFalse(WebLlmBridge::workerAvailable());
    }

    public function test_result_requires_id_and_claim_token(): void
    {
        $this->actAsAdmin();

        $this->assertSame(400, $this->post('/result')->get_status());
    }

    public function test_result_with_a_stale_claim_is_a_conflict(): void
    {
        $this->actAsAdmin();

        $response = $this->post('/result', ['id' => 999999, 'claim_token' => 'stale', 'result' => ['choices' => []]]);

        $this->assertSame(409, $response->get_status());
    }

    public function test_result_completes_a_claimed_job(): void
    {
        global $wpdb;

        $this->actAsAdmin();

        $wpdb->insert(
            WebLlmBridge::table(),
            [
                'status'     => 'pending',
                'model'      => self::MODEL,
                'payload'    => '{}',
                'created_at' => time(),
                'updated_at' => time(),
            ]
        );

        $job = WebLlmBridge::claimNext(self::MODEL);
        $this->assertIsArray($job);

        $response = $this->post(
            '/result',
            ['id' => $job['id'], 'claim_token' => $job['claim_token'], 'result' => ['choices' => []]]
        );

        $this->assertSame(200, $response->get_status());

        $table = WebLlmBridge::table();
        $this->assertSame('done', $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $job['id'])));
    }
}
