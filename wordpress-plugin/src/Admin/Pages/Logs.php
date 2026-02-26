<?php

declare(strict_types=1);

namespace AiWpCache\Admin\Pages;

use AiWpCache\Storage\Logger;

/**
 * Admin logs page – shows recent log entries with level filtering.
 */
final class Logs
{
    private const NONCE_ACTION = 'aiwpc_clear_logs';

    /** Valid log levels for the filter dropdown. */
    private const LEVELS = ['', 'debug', 'info', 'warn', 'error'];

    public function __construct(private readonly Logger $logger) {}

    /** Render the logs page. */
    public function render(): void
    {
        // Handle "Clear Logs" POST action.
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['_aiwpc_clear_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_aiwpc_clear_nonce'])), self::NONCE_ACTION)
            && current_user_can('manage_options')
        ) {
            $this->logger->clearLogs();
            echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared.', 'ai-wp-dynamic-cache') . '</p></div>';
        }

        // Determine the active level filter.
        $levelFilter = isset($_GET['log_level']) && in_array($_GET['log_level'], self::LEVELS, true)
            ? sanitize_text_field(wp_unslash((string) $_GET['log_level']))
            : '';

        $entries = $this->logger->getLogs(100, $levelFilter);
        ?>
        <div class="wrap aiwpc-wrap">
            <h1><?php esc_html_e('AI Cache – Logs', 'ai-wp-dynamic-cache'); ?></h1>

            <div class="aiwpc-log-controls">
                <form method="get" action="" style="display:inline-block;">
                    <input type="hidden" name="page" value="ai-wp-cache-logs">
                    <label for="aiwpc-log-level-filter"><?php esc_html_e('Filter by level:', 'ai-wp-dynamic-cache'); ?></label>
                    <select id="aiwpc-log-level-filter" name="log_level" onchange="this.form.submit()">
                        <?php foreach (self::LEVELS as $level) : ?>
                            <option value="<?php echo esc_attr($level); ?>" <?php selected($levelFilter, $level); ?>>
                                <?php echo $level === '' ? esc_html__('All', 'ai-wp-dynamic-cache') : esc_html(ucfirst($level)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <form method="post" action="" style="display:inline-block; margin-left: 1em;">
                    <?php wp_nonce_field(self::NONCE_ACTION, '_aiwpc_clear_nonce'); ?>
                    <button type="submit" class="button button-secondary" id="aiwpc-clear-logs"
                            onclick="return confirm('<?php esc_attr_e('Clear all log entries?', 'ai-wp-dynamic-cache'); ?>')">
                        <?php esc_html_e('Clear Logs', 'ai-wp-dynamic-cache'); ?>
                    </button>
                </form>
            </div>

            <?php if (empty($entries)) : ?>
                <p><?php esc_html_e('No log entries found.', 'ai-wp-dynamic-cache'); ?></p>
            <?php else : ?>
                <table class="widefat aiwpc-log-table" id="aiwpc-log-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'ai-wp-dynamic-cache'); ?></th>
                            <th><?php esc_html_e('Level', 'ai-wp-dynamic-cache'); ?></th>
                            <th><?php esc_html_e('Message', 'ai-wp-dynamic-cache'); ?></th>
                            <th><?php esc_html_e('Context', 'ai-wp-dynamic-cache'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry) : ?>
                            <tr data-level="<?php echo esc_attr((string) $entry['level']); ?>">
                                <td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $entry['timestamp'])); ?></td>
                                <td>
                                    <span class="aiwpc-level aiwpc-level--<?php echo esc_attr((string) $entry['level']); ?>">
                                        <?php echo esc_html(strtoupper((string) $entry['level'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html((string) $entry['message']); ?></td>
                                <td>
                                    <?php if (!empty($entry['context'])) : ?>
                                        <code><?php echo esc_html((string) wp_json_encode($entry['context'])); ?></code>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
