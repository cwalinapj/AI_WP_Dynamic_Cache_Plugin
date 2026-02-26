<?php

declare(strict_types=1);

namespace AiWpCache\Agent;

use AiWpCache\Api\Client;

/**
 * Aggregates per-request telemetry metrics and flushes them to the worker
 * in a single batched request once per aggregation window.
 *
 * Metrics are stored in a WordPress transient to survive multiple PHP processes
 * within the same aggregation window.
 */
final class Telemetry
{
    private const TRANSIENT_KEY = 'aiwpc_telemetry';
    private const WINDOW_TTL    = 60; // seconds

    public function __construct(private readonly Client $client) {}

    /** Record a cache-hit event. */
    public function recordCacheHit(): void
    {
        $this->increment('cache_hits');
    }

    /** Record a cache-miss event. */
    public function recordCacheMiss(): void
    {
        $this->increment('cache_misses');
    }

    /** Record a cache purge event. */
    public function recordPurge(): void
    {
        $this->increment('purges');
    }

    /** Record a preload event. */
    public function recordPreload(): void
    {
        $this->increment('preloads');
    }

    /**
     * Send the aggregated metrics to the worker and reset the counters.
     *
     * Should be called from a shutdown hook or a scheduled event.
     */
    public function flush(): void
    {
        $metrics = $this->loadMetrics();
        if (empty($metrics)) {
            return;
        }

        $sent = $this->client->sendSignals([
            [
                'type'      => 'telemetry',
                'metrics'   => $metrics,
                'timestamp' => time(),
            ],
        ]);

        if ($sent) {
            delete_transient(self::TRANSIENT_KEY);
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** Atomically increment a counter inside the transient. */
    private function increment(string $counter): void
    {
        $metrics = $this->loadMetrics();
        $metrics[$counter] = ($metrics[$counter] ?? 0) + 1;
        set_transient(self::TRANSIENT_KEY, $metrics, self::WINDOW_TTL);
    }

    /**
     * Load current metrics from the transient.
     *
     * @return array<string, int>
     */
    private function loadMetrics(): array
    {
        $raw = get_transient(self::TRANSIENT_KEY);
        return is_array($raw) ? $raw : [];
    }
}
