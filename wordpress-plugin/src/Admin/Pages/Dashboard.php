<?php

declare(strict_types=1);

namespace AiWpCache\Admin\Pages;

use AiWpCache\Api\Client;
use AiWpCache\Storage\Logger;
use AiWpCache\Storage\Options;

/**
 * Admin dashboard page – shows plugin status, recent stats, and quick actions.
 */
final class Dashboard
{
    public function __construct(
        private readonly Options $options,
        private readonly Logger  $logger,
        private readonly Client  $client,
    ) {}

    /** Render the dashboard HTML. */
    public function render(): void
    {
        $isEnabled  = $this->options->isEnabled();
        $workerUrl  = $this->options->getWorkerUrl();
        $policy     = $this->options->getActivePolicy();
        $recentLogs = $this->logger->getLogs(5);

        $hits   = $this->countByType($recentLogs, 'cache_hit');
        $misses = $this->countByType($recentLogs, 'cache_miss');
        ?>
        <div class="wrap aiwpc-wrap">
            <h1><?php esc_html_e('AI Cache – Dashboard', 'ai-wp-dynamic-cache'); ?></h1>

            <div class="aiwpc-cards">
                <div class="aiwpc-card">
                    <h3><?php esc_html_e('Status', 'ai-wp-dynamic-cache'); ?></h3>
                    <span class="aiwpc-badge <?php echo $isEnabled ? 'aiwpc-badge--green' : 'aiwpc-badge--red'; ?>">
                        <?php echo $isEnabled ? esc_html__('Enabled', 'ai-wp-dynamic-cache') : esc_html__('Disabled', 'ai-wp-dynamic-cache'); ?>
                    </span>
                </div>

                <div class="aiwpc-card">
                    <h3><?php esc_html_e('Worker URL', 'ai-wp-dynamic-cache'); ?></h3>
                    <code><?php echo $workerUrl !== '' ? esc_html($workerUrl) : esc_html__('(not configured)', 'ai-wp-dynamic-cache'); ?></code>
                </div>

                <div class="aiwpc-card">
                    <h3><?php esc_html_e('Active Policy', 'ai-wp-dynamic-cache'); ?></h3>
                    <code><?php echo esc_html($policy); ?></code>
                </div>

                <div class="aiwpc-card">
                    <h3><?php esc_html_e('Cache Hits (recent)', 'ai-wp-dynamic-cache'); ?></h3>
                    <span class="aiwpc-stat"><?php echo esc_html((string) $hits); ?></span>
                </div>

                <div class="aiwpc-card">
                    <h3><?php esc_html_e('Cache Misses (recent)', 'ai-wp-dynamic-cache'); ?></h3>
                    <span class="aiwpc-stat"><?php echo esc_html((string) $misses); ?></span>
                </div>
            </div>

            <div class="aiwpc-actions">
                <button id="aiwpc-test-connection" class="button button-secondary">
                    <?php esc_html_e('Test Connection', 'ai-wp-dynamic-cache'); ?>
                </button>
                <span id="aiwpc-connection-result" class="aiwpc-inline-result"></span>

                <button id="aiwpc-purge-all" class="button button-secondary">
                    <?php esc_html_e('Purge All', 'ai-wp-dynamic-cache'); ?>
                </button>
                <span id="aiwpc-purge-result" class="aiwpc-inline-result"></span>
            </div>

            <h2><?php esc_html_e('Recent Log Entries', 'ai-wp-dynamic-cache'); ?></h2>
            <?php if (empty($recentLogs)) : ?>
                <p><?php esc_html_e('No log entries yet.', 'ai-wp-dynamic-cache'); ?></p>
            <?php else : ?>
                <table class="widefat aiwpc-log-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'ai-wp-dynamic-cache'); ?></th>
                            <th><?php esc_html_e('Level', 'ai-wp-dynamic-cache'); ?></th>
                            <th><?php esc_html_e('Message', 'ai-wp-dynamic-cache'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $entry['timestamp'])); ?></td>
                                <td><span class="aiwpc-level aiwpc-level--<?php echo esc_attr((string) $entry['level']); ?>"><?php echo esc_html((string) $entry['level']); ?></span></td>
                                <td><?php echo esc_html((string) $entry['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=ai-wp-cache-logs')); ?>"><?php esc_html_e('View all logs →', 'ai-wp-dynamic-cache'); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Count log entries whose context 'type' field matches the given value.
     *
     * @param list<array<string, mixed>> $logs
     */
    private function countByType(array $logs, string $type): int
    {
        return count(array_filter(
            $logs,
            static fn(array $e): bool => isset($e['context']['type']) && $e['context']['type'] === $type
        ));
    }
}
