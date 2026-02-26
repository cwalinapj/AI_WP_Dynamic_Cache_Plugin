<?php

declare(strict_types=1);

namespace AiWpCache\Admin\Pages;

use AiWpCache\Storage\Options;

/**
 * Admin experiments page – shows the strategy matrix and lets admins select
 * the active caching strategy.
 */
final class Experiments
{
    private const NONCE_ACTION = 'aiwpc_save_experiment';
    private const NONCE_FIELD  = '_aiwpc_exp_nonce';

    /** Human-readable description for each strategy. */
    private const STRATEGY_DESCRIPTIONS = [
        'disk_only' => 'Serve from origin disk cache only. Lowest complexity.',
        'disk_edge' => 'Disk cache at origin + Cloudflare edge cache. Recommended.',
        'r2'        => 'Cloudflare R2 object storage backend. Best for large assets.',
        'full'      => 'All tiers: disk + edge + R2 + KV. Maximum performance.',
    ];

    public function __construct(private readonly Options $options) {}

    /** Render the experiments page. */
    public function render(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleSave();
        }

        $activeStrategy = $this->options->getActivePolicy();
        $metrics        = $this->options->get('experiment_metrics', []);
        ?>
        <div class="wrap aiwpc-wrap">
            <h1><?php esc_html_e('AI Cache – Experiments', 'ai-wp-dynamic-cache'); ?></h1>
            <p><?php esc_html_e('Choose a caching strategy. Changes take effect immediately.', 'ai-wp-dynamic-cache'); ?></p>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <table class="widefat aiwpc-strategy-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Strategy', 'ai-wp-dynamic-cache'); ?></th>
                            <th><?php esc_html_e('Description', 'ai-wp-dynamic-cache'); ?></th>
                            <th><?php esc_html_e('Active', 'ai-wp-dynamic-cache'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (self::STRATEGY_DESCRIPTIONS as $strategy => $description) : ?>
                            <tr class="<?php echo $activeStrategy === $strategy ? 'aiwpc-row--active' : ''; ?>">
                                <td><strong><?php echo esc_html($strategy); ?></strong></td>
                                <td><?php echo esc_html($description); ?></td>
                                <td>
                                    <input type="radio" name="aiwpc_strategy"
                                           value="<?php echo esc_attr($strategy); ?>"
                                           <?php checked($activeStrategy, $strategy); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(__('Apply Strategy', 'ai-wp-dynamic-cache')); ?>
            </form>

            <?php if (!empty($metrics) && is_array($metrics)) : ?>
                <h2><?php esc_html_e('Benchmark Results', 'ai-wp-dynamic-cache'); ?></h2>
                <table class="widefat aiwpc-metrics-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Metric', 'ai-wp-dynamic-cache'); ?></th>
                            <th><?php esc_html_e('Value', 'ai-wp-dynamic-cache'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($metrics as $key => $value) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $key); ?></td>
                                <td><?php echo esc_html((string) $value); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Handle strategy form submission. */
    private function handleSave(): void
    {
        if (
            !isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD])), self::NONCE_ACTION)
            || !current_user_can('manage_options')
        ) {
            wp_die(esc_html__('Security check failed.', 'ai-wp-dynamic-cache'));
        }

        if (isset($_POST['aiwpc_strategy'])) {
            $this->options->setActivePolicy(sanitize_text_field(wp_unslash((string) $_POST['aiwpc_strategy'])));
            add_settings_error('aiwpc_exp', 'saved', __('Strategy updated.', 'ai-wp-dynamic-cache'), 'updated');
        }

        settings_errors('aiwpc_exp');
    }
}
