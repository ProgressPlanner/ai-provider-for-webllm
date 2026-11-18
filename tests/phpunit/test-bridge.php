<?php

/**
 * Tests for the jobs table and the worker side of the bridge.
 *
 * WebLlmBridge::run() is only tested on its fail-fast paths: the happy path
 * blocks until a browser worker answers.
 *
 * @package WordPress\WebLlmAiProvider
 */

declare(strict_types=1);

use WordPress\WebLlmAiProvider\Bridge\WebLlmBridge;

class Test_Bridge extends WP_UnitTestCase
{
    private const MODEL = 'Test-Model-MLC';

    /**
     * Inserts a pending job the way WebLlmBridge::run() does.
     *
     * @param string               $model   The model the job is for.
     * @param array<string, mixed> $payload The job payload.
     * @return int The job ID.
     */
    private function insertJob(string $model = self::MODEL, array $payload = ['model' => self::MODEL]): int
    {
        global $wpdb;

        $wpdb->insert(
            WebLlmBridge::table(),
            [
                'status'     => 'pending',
                'model'      => $model,
                'payload'    => (string) wp_json_encode($payload),
                'created_at' => time(),
                'updated_at' => time(),
            ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Reads a single column of a job row.
     *
     * @param int    $id     The job ID.
     * @param string $column The column to read.
     * @return string|null The value.
     */
    private function jobColumn(int $id, string $column): ?string
    {
        global $wpdb;

        $table = WebLlmBridge::table();

        return $wpdb->get_var($wpdb->prepare("SELECT {$column} FROM {$table} WHERE id = %d", $id));
    }

    public function test_table_name_uses_the_site_prefix(): void
    {
        global $wpdb;

        $this->assertSame($wpdb->prefix . 'ai_webllm_jobs', WebLlmBridge::table());
    }

    public function test_jobs_table_is_installed(): void
    {
        global $wpdb;

        $table = WebLlmBridge::table();

        $this->assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
        $this->assertNotEmpty(get_option('ai_provider_webllm_db_version'));
    }

    public function test_no_worker_is_available_without_a_heartbeat(): void
    {
        delete_option('ai_provider_webllm_worker');

        $this->assertFalse(WebLlmBridge::workerAvailable());
    }

    public function test_ready_heartbeat_makes_the_worker_available(): void
    {
        WebLlmBridge::heartbeat(true, self::MODEL);

        $this->assertTrue(WebLlmBridge::workerAvailable());
        $this->assertTrue(WebLlmBridge::workerAvailable(self::MODEL));
        $this->assertFalse(WebLlmBridge::workerAvailable('Another-Model-MLC'));
    }

    public function test_loading_worker_is_not_available(): void
    {
        WebLlmBridge::heartbeat(false, self::MODEL);

        $this->assertFalse(WebLlmBridge::workerAvailable());
    }

    public function test_stale_heartbeat_is_not_available(): void
    {
        update_option(
            'ai_provider_webllm_worker',
            ['t' => time() - HOUR_IN_SECONDS, 'ready' => true, 'model' => self::MODEL]
        );

        $this->assertFalse(WebLlmBridge::workerAvailable());
    }

    public function test_run_requires_a_model(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A WebLLM model is required');

        WebLlmBridge::run(['messages' => []]);
    }

    public function test_run_fails_fast_without_a_worker(): void
    {
        delete_option('ai_provider_webllm_worker');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No WebLLM worker is connected');

        WebLlmBridge::run(['model' => self::MODEL]);
    }

    public function test_claim_next_needs_a_model(): void
    {
        $this->insertJob();

        $this->assertNull(WebLlmBridge::claimNext(''));
    }

    public function test_claim_next_returns_null_when_there_are_no_jobs(): void
    {
        $this->assertNull(WebLlmBridge::claimNext(self::MODEL));
    }

    public function test_claim_next_only_claims_jobs_for_the_loaded_model(): void
    {
        $this->insertJob();

        $this->assertNull(WebLlmBridge::claimNext('Another-Model-MLC'));
    }

    public function test_claim_next_claims_the_oldest_pending_job_once(): void
    {
        $first = $this->insertJob(self::MODEL, ['model' => self::MODEL, 'n' => 1]);
        $this->insertJob(self::MODEL, ['model' => self::MODEL, 'n' => 2]);

        $job = WebLlmBridge::claimNext(self::MODEL);

        $this->assertIsArray($job);
        $this->assertSame($first, $job['id']);
        $this->assertSame(['model' => self::MODEL, 'n' => 1], $job['payload']);
        $this->assertNotSame('', $job['claim_token']);
        $this->assertSame('claimed', $this->jobColumn($first, 'status'));

        $next = WebLlmBridge::claimNext(self::MODEL);

        $this->assertIsArray($next);
        $this->assertNotSame($first, $next['id']);
        $this->assertNull(WebLlmBridge::claimNext(self::MODEL));
    }

    public function test_abandoned_claim_is_released_for_retry(): void
    {
        global $wpdb;

        $id  = $this->insertJob();
        $job = WebLlmBridge::claimNext(self::MODEL);

        $this->assertIsArray($job);

        $wpdb->update(WebLlmBridge::table(), ['updated_at' => time() - HOUR_IN_SECONDS], ['id' => $id]);

        $retry = WebLlmBridge::claimNext(self::MODEL);

        $this->assertIsArray($retry);
        $this->assertSame($id, $retry['id']);
        $this->assertNotSame($job['claim_token'], $retry['claim_token']);
    }

    public function test_complete_job_stores_the_result(): void
    {
        $id  = $this->insertJob();
        $job = WebLlmBridge::claimNext(self::MODEL);

        $this->assertIsArray($job);
        $this->assertTrue(WebLlmBridge::completeJob($id, $job['claim_token'], ['choices' => []], null));
        $this->assertSame('done', $this->jobColumn($id, 'status'));
        $this->assertSame('{"choices":[]}', $this->jobColumn($id, 'result'));
    }

    public function test_complete_job_stores_an_error(): void
    {
        $id  = $this->insertJob();
        $job = WebLlmBridge::claimNext(self::MODEL);

        $this->assertIsArray($job);
        $this->assertTrue(WebLlmBridge::completeJob($id, $job['claim_token'], null, 'Out of memory'));
        $this->assertSame('error', $this->jobColumn($id, 'status'));
        $this->assertSame('Out of memory', $this->jobColumn($id, 'error'));
    }

    public function test_complete_job_rejects_a_wrong_or_empty_token(): void
    {
        $id = $this->insertJob();

        $this->assertIsArray(WebLlmBridge::claimNext(self::MODEL));
        $this->assertFalse(WebLlmBridge::completeJob($id, '', ['choices' => []], null));
        $this->assertFalse(WebLlmBridge::completeJob($id, 'not-the-token', ['choices' => []], null));
        $this->assertSame('claimed', $this->jobColumn($id, 'status'));
    }

    public function test_complete_job_rejects_an_unclaimed_job(): void
    {
        $id = $this->insertJob();

        $this->assertFalse(WebLlmBridge::completeJob($id, 'any-token', ['choices' => []], null));
        $this->assertSame('pending', $this->jobColumn($id, 'status'));
    }
}
