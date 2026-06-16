<?php

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider\Bridge;

use RuntimeException;

/**
 * Bridges server-side (PHP) generation requests to a browser worker running WebLLM.
 *
 * PHP cannot push to a browser, so an open admin tab (the "worker") polls for jobs,
 * runs the model browser-side, and posts the result back. A PHP generation call
 * enqueues a job and blocks until the worker returns a result or a timeout elapses.
 *
 * This only works while a worker with the model loaded is connected; otherwise
 * {@see self::run()} throws. There is no headless path (cron/WP-CLI have no browser).
 *
 * @since 0.3.0
 */
class WebLlmBridge
{
    /**
     * Database schema version; bump to trigger a lazy table (re)install.
     *
     * @var string
     */
    private const DB_VERSION = '2';

    /**
     * Option storing the installed schema version.
     *
     * @var string
     */
    private const DB_VERSION_OPTION = 'ai_provider_webllm_db_version';

    /**
     * Option storing the latest worker heartbeat: ['t' => int, 'ready' => bool, 'model' => string].
     *
     * @var string
     */
    private const WORKER_OPTION = 'ai_provider_webllm_worker';

    /**
     * Seconds a heartbeat stays valid before a worker is considered gone.
     *
     * @var int
     */
    private const WORKER_TTL = 30;

    /**
     * Poll interval while waiting for a result, in microseconds.
     *
     * @var int
     */
    private const POLL_INTERVAL_US = 250000;

    /**
     * Seconds before an unfinished claimed job can be retried by another worker.
     *
     * @var int
     */
    private const CLAIM_TTL = 30;

    /**
     * Returns the jobs table name.
     *
     * @since 0.3.0
     *
     * @return string The prefixed table name.
     */
    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ai_webllm_jobs';
    }

    /**
     * Creates the jobs table if the schema version is missing or outdated.
     *
     * @since 0.3.0
     *
     * @return void
     */
    public static function maybeInstall(): void
    {
        if (self::DB_VERSION === get_option(self::DB_VERSION_OPTION)) {
            return;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                claim_token VARCHAR(32) DEFAULT NULL,
                model VARCHAR(191) NOT NULL DEFAULT '',
                payload LONGTEXT NOT NULL,
                result LONGTEXT DEFAULT NULL,
                error TEXT DEFAULT NULL,
                created_at INT NOT NULL,
                updated_at INT NOT NULL,
                PRIMARY KEY  (id),
                KEY status (status),
                KEY status_model (status, model)
            ) {$collate};"
        );

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Records a worker heartbeat.
     *
     * @since 0.3.0
     *
     * @param bool   $ready Whether the worker has the model loaded and can serve jobs.
     * @param string $model The model the worker has loaded.
     * @return void
     */
    public static function heartbeat(bool $ready, string $model): void
    {
        update_option(
            self::WORKER_OPTION,
            ['t' => time(), 'ready' => $ready, 'model' => $model],
            false
        );
    }

    /**
     * Whether a worker is connected, ready, and recently seen.
     *
     * @since 0.3.0
     *
     * @return bool True if a ready worker is available.
     */
    public static function workerAvailable(string $model = ''): bool
    {
        $worker = get_option(self::WORKER_OPTION);

        $available = is_array($worker)
            && !empty($worker['ready'])
            && (time() - (int) ($worker['t'] ?? 0)) <= self::WORKER_TTL;

        if (!$available) {
            return false;
        }

        if ('' === $model) {
            return true;
        }

        return isset($worker['model']) && (string) $worker['model'] === $model;
    }

    /**
     * Enqueues a generation job and blocks until the worker returns a result.
     *
     * @since 0.3.0
     *
     * @param array<string, mixed> $payload OpenAI-shaped chat-completions params for WebLLM.
     * @param int                  $timeout Maximum seconds to wait.
     * @return array<string, mixed> The OpenAI-shaped completion returned by the worker.
     * @throws RuntimeException If no worker is available, the job fails, or it times out.
     */
    public static function run(array $payload, int $timeout = 120): array
    {
        $model = isset($payload['model']) && is_string($payload['model'])
            ? sanitize_text_field($payload['model'])
            : '';

        if ('' === $model) {
            throw new RuntimeException('A WebLLM model is required before enqueueing a generation job.');
        }

        if (!self::workerAvailable($model)) {
            throw new RuntimeException(
                sprintf(
                    'No WebLLM worker is connected for model %s. Open the WebLLM worker page in a browser '
                    . 'and wait for that model to finish loading, then retry.',
                    $model
                )
            );
        }

        // Release the PHP session lock (if any plugin opened one) before blocking, so the
        // worker's own same-user requests are not locked out for the duration of the wait.
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        global $wpdb;

        $now = time();
        $wpdb->insert(
            self::table(),
            [
                'status'     => 'pending',
                'model'      => $model,
                'payload'    => (string) wp_json_encode($payload),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $id = (int) $wpdb->insert_id;
        if (0 === $id) {
            throw new RuntimeException('Failed to enqueue the WebLLM job.');
        }

        $table    = self::table();
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            usleep(self::POLL_INTERVAL_US);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT status, result, error FROM {$table} WHERE id = %d", $id),
                ARRAY_A
            );

            if (null === $row) {
                throw new RuntimeException('The WebLLM job disappeared before completing.');
            }

            if ('done' === $row['status']) {
                self::deleteJob($id);
                $result = json_decode((string) $row['result'], true);

                return is_array($result) ? $result : [];
            }

            if ('error' === $row['status']) {
                $error = (string) $row['error'];
                self::deleteJob($id);

                throw new RuntimeException('WebLLM worker error: ' . $error);
            }

            // Still waiting to be picked up and the worker has gone away: fail fast
            // rather than blocking for the full timeout. (Safe because the worker
            // heartbeats on a timer even while busy, so a stale heartbeat means gone.)
            if ('pending' === $row['status'] && !self::workerAvailable($model)) {
                self::deleteJob($id);

                throw new RuntimeException(
                    sprintf('The WebLLM worker for model %s disconnected before starting the job.', $model)
                );
            }
        }

        self::deleteJob($id);

        throw new RuntimeException(sprintf('WebLLM generation timed out after %d seconds.', $timeout));
    }

    /**
     * Atomically claims the oldest pending job (worker side).
     *
     * @since 0.3.0
     *
     * @param string $model The model currently loaded by the worker.
     * @return array{id: int, claim_token: string, payload: array<string, mixed>}|null The claimed job, or null if none.
     */
    public static function claimNext(string $model): ?array
    {
        global $wpdb;

        $model = sanitize_text_field($model);
        if ('' === $model) {
            return null;
        }

        $table = self::table();
        $now   = time();

        // Allow another compatible worker to retry abandoned claims.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = 'pending', claim_token = NULL, updated_at = %d WHERE status = 'claimed' AND updated_at < %d",
                $now,
                $now - self::CLAIM_TTL
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, payload FROM {$table} WHERE status = 'pending' AND model = %s ORDER BY id ASC LIMIT 1",
                $model
            ),
            ARRAY_A
        );
        if (null === $row) {
            return null;
        }

        $id    = (int) $row['id'];
        $token = wp_generate_password(20, false);

        // Guarded update: only succeeds if still pending. 0 rows = another worker won the race.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = 'claimed', claim_token = %s, updated_at = %d WHERE id = %d AND status = 'pending'",
                $token,
                time(),
                $id
            )
        );

        if (1 !== $claimed) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true);

        return ['id' => $id, 'claim_token' => $token, 'payload' => is_array($payload) ? $payload : []];
    }

    /**
     * Records a worker's result (or error) for a job.
     *
     * @since 0.3.0
     *
     * @param int                       $id         The job ID.
     * @param string                    $claimToken The token assigned when the job was claimed.
     * @param array<string, mixed>|null $result     The OpenAI-shaped completion, or null on error.
     * @param string|null               $error      An error message, or null on success.
     * @return bool Whether the job was completed.
     */
    public static function completeJob(int $id, string $claimToken, ?array $result, ?string $error): bool
    {
        global $wpdb;

        if ('' === $claimToken) {
            return false;
        }

        if (null !== $error) {
            $updated = $wpdb->update(
                self::table(),
                ['status' => 'error', 'error' => $error, 'updated_at' => time()],
                ['id' => $id, 'claim_token' => $claimToken, 'status' => 'claimed']
            );

            return 1 === $updated;
        }

        $updated = $wpdb->update(
            self::table(),
            ['status' => 'done', 'result' => (string) wp_json_encode($result), 'updated_at' => time()],
            ['id' => $id, 'claim_token' => $claimToken, 'status' => 'claimed']
        );

        return 1 === $updated;
    }

    /**
     * Deletes a job row.
     *
     * @since 0.3.0
     *
     * @param int $id The job ID.
     * @return void
     */
    private static function deleteJob(int $id): void
    {
        global $wpdb;

        $wpdb->delete(self::table(), ['id' => $id]);
    }
}
