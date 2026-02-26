<?php

declare(strict_types=1);

namespace AiWpCache\Admin\Pages;

use AiWpCache\Storage\Options;

/**
 * Admin policies configuration page.
 *
 * Provides a nonce-protected form for adjusting all plugin settings.
 */
final class Policies
{
    private const NONCE_ACTION = 'aiwpc_save_policies';
    private const NONCE_FIELD  = '_aiwpc_nonce';

    public function __construct(private readonly Options $options) {}

    /** Render and (if POST) save the policies form. */
    public function render(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->save();
        }

        $workerUrl     = $this->options->getWorkerUrl();
        $hmacSecret    = $this->options->getHmacSecret();
        $cacheTtl      = $this->options->getCacheTtl();
        $activePolicy  = $this->options->getActivePolicy();
        $isEnabled     = $this->options->isEnabled();
        $bypassCookies = (string) $this->options->get('bypass_cookies', '');
        ?>
        <div class="wrap aiwpc-wrap">
            <h1><?php esc_html_e('AI Cache – Policies', 'ai-wp-dynamic-cache'); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="aiwpc_enabled"><?php esc_html_e('Enable Plugin', 'ai-wp-dynamic-cache'); ?></label></th>
                        <td>
                            <input type="checkbox" id="aiwpc_enabled" name="aiwpc_enabled" value="1" <?php checked($isEnabled); ?>>
                            <p class="description"><?php esc_html_e('Uncheck to disable all cache header injection.', 'ai-wp-dynamic-cache'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiwpc_worker_url"><?php esc_html_e('Worker URL', 'ai-wp-dynamic-cache'); ?></label></th>
                        <td>
                            <input type="url" id="aiwpc_worker_url" name="aiwpc_worker_url"
                                   value="<?php echo esc_attr($workerUrl); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Base URL of your Cloudflare Worker (no trailing slash).', 'ai-wp-dynamic-cache'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiwpc_hmac_secret"><?php esc_html_e('HMAC Secret', 'ai-wp-dynamic-cache'); ?></label></th>
                        <td>
                            <input type="password" id="aiwpc_hmac_secret" name="aiwpc_hmac_secret"
                                   value="<?php echo esc_attr($hmacSecret); ?>" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php esc_html_e('Shared secret for signing API requests (≥ 32 characters recommended).', 'ai-wp-dynamic-cache'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiwpc_cache_ttl"><?php esc_html_e('Cache TTL (seconds)', 'ai-wp-dynamic-cache'); ?></label></th>
                        <td>
                            <input type="number" id="aiwpc_cache_ttl" name="aiwpc_cache_ttl"
                                   value="<?php echo esc_attr((string) $cacheTtl); ?>" min="1" class="small-text">
                            <p class="description"><?php esc_html_e('Default Cache-Control max-age value for frontend pages.', 'ai-wp-dynamic-cache'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiwpc_active_policy"><?php esc_html_e('Active Policy', 'ai-wp-dynamic-cache'); ?></label></th>
                        <td>
                            <select id="aiwpc_active_policy" name="aiwpc_active_policy">
                                <?php foreach (['default', 'disk_only', 'disk_edge', 'r2', 'full'] as $policy) : ?>
                                    <option value="<?php echo esc_attr($policy); ?>" <?php selected($activePolicy, $policy); ?>><?php echo esc_html($policy); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiwpc_bypass_cookies"><?php esc_html_e('Bypass Cookies', 'ai-wp-dynamic-cache'); ?></label></th>
                        <td>
                            <textarea id="aiwpc_bypass_cookies" name="aiwpc_bypass_cookies"
                                      rows="4" class="large-text"><?php echo esc_textarea($bypassCookies); ?></textarea>
                            <p class="description"><?php esc_html_e('One cookie name prefix per line. Requests with matching cookies bypass the cache.', 'ai-wp-dynamic-cache'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'ai-wp-dynamic-cache')); ?>
            </form>
        </div>
        <?php
    }

    /** Process the submitted form data. */
    public function save(): void
    {
        if (
            !isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD])), self::NONCE_ACTION)
            || !current_user_can('manage_options')
        ) {
            wp_die(esc_html__('Security check failed.', 'ai-wp-dynamic-cache'));
        }

        $this->options->set('enabled', !empty($_POST['aiwpc_enabled']));

        if (isset($_POST['aiwpc_worker_url'])) {
            $this->options->setWorkerUrl(sanitize_text_field(wp_unslash((string) $_POST['aiwpc_worker_url'])));
        }

        if (isset($_POST['aiwpc_hmac_secret']) && $_POST['aiwpc_hmac_secret'] !== '') {
            $this->options->setHmacSecret(sanitize_text_field(wp_unslash((string) $_POST['aiwpc_hmac_secret'])));
        }

        if (isset($_POST['aiwpc_cache_ttl'])) {
            $this->options->set('cache_ttl', max(1, (int) $_POST['aiwpc_cache_ttl']));
        }

        if (isset($_POST['aiwpc_active_policy'])) {
            $this->options->setActivePolicy(sanitize_text_field(wp_unslash((string) $_POST['aiwpc_active_policy'])));
        }

        if (isset($_POST['aiwpc_bypass_cookies'])) {
            $this->options->set('bypass_cookies', sanitize_textarea_field(wp_unslash((string) $_POST['aiwpc_bypass_cookies'])));
        }

        add_settings_error('aiwpc_policies', 'saved', __('Settings saved.', 'ai-wp-dynamic-cache'), 'updated');
        settings_errors('aiwpc_policies');
    }
}
