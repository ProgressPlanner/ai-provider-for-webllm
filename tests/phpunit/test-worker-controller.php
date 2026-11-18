<?php

/**
 * Tests for the browser-worker script loader.
 *
 * @package WordPress\WebLlmAiProvider
 */

declare(strict_types=1);

use WordPress\WebLlmAiProvider\Worker\WorkerController;

class Test_Worker_Controller extends WP_UnitTestCase
{
    public function tear_down()
    {
        wp_dequeue_script(WorkerController::HANDLE);
        wp_deregister_script(WorkerController::HANDLE);

        parent::tear_down();
    }

    public function test_worker_is_disabled_by_default(): void
    {
        delete_option(WorkerController::OPTION_ENABLED);

        $this->assertFalse(WorkerController::isEnabled());
    }

    public function test_worker_can_be_enabled(): void
    {
        update_option(WorkerController::OPTION_ENABLED, '1');

        $this->assertTrue(WorkerController::isEnabled());
    }

    public function test_enqueue_hook_is_registered(): void
    {
        $this->assertSame(10, has_action('admin_enqueue_scripts', [WorkerController::class, 'enqueue']));
    }

    public function test_script_is_not_enqueued_when_disabled(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(WorkerController::OPTION_ENABLED);

        WorkerController::enqueue();

        $this->assertFalse(wp_script_is(WorkerController::HANDLE, 'enqueued'));
    }

    public function test_script_is_not_enqueued_without_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        update_option(WorkerController::OPTION_ENABLED, '1');

        WorkerController::enqueue();

        $this->assertFalse(wp_script_is(WorkerController::HANDLE, 'enqueued'));
    }

    public function test_script_is_enqueued_for_enabled_admin(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(WorkerController::OPTION_ENABLED, '1');

        WorkerController::enqueue();

        $this->assertTrue(wp_script_is(WorkerController::HANDLE, 'enqueued'));
        $this->assertStringContainsString(
            'aiProviderWebllmWorker',
            (string) wp_scripts()->get_data(WorkerController::HANDLE, 'data')
        );
    }
}
