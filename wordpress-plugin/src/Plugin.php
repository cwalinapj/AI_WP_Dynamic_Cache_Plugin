<?php

declare(strict_types=1);

namespace AI\WPDynamicCache;

final class Plugin
{
    private const OPTION_KEY = 'ai_wpdyn_modular_settings';

    public static function bootstrap(): void
    {
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_init', [self::class, 'handleSubmit']);
        add_action('send_headers', [self::class, 'sendHeaders']);
    }

    public static function registerAdminMenu(): void
    {
        add_menu_page(
            'AI Dynamic Cache (Modular)',
            'AI Dynamic Cache (Modular)',
            'manage_options',
            'ai-wp-dynamic-cache-modular',
            [self::class, 'renderPage'],
            'dashicons-performance',
            82
        );
    }

    public static function handleSubmit(): void
    {
        if (!isset($_POST['ai_wpdyn_modular_submit']) || !current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('ai_wpdyn_modular_save', 'ai_wpdyn_modular_nonce');

        $current = self::getSettings();
        $next = [
            'worker_base_url' => isset($_POST['worker_base_url']) ? esc_url_raw(trim((string)wp_unslash($_POST['worker_base_url']))) : $current['worker_base_url'],
            'plugin_shared_secret' => isset($_POST['plugin_shared_secret']) ? trim((string)wp_unslash($_POST['plugin_shared_secret'])) : $current['plugin_shared_secret'],
            'site_id' => isset($_POST['site_id']) ? sanitize_text_field(trim((string)wp_unslash($_POST['site_id']))) : $current['site_id'],
            'cache_strategy' => isset($_POST['cache_strategy']) ? self::normalizeStrategy((string)wp_unslash($_POST['cache_strategy'])) : $current['cache_strategy'],
            'ttl_seconds' => isset($_POST['ttl_seconds']) ? max(30, min(86400, (int)wp_unslash($_POST['ttl_seconds']))) : $current['ttl_seconds'],
        ];

        update_option(self::OPTION_KEY, $next, false);
        add_settings_error('ai_wpdyn_modular_messages', 'saved', 'Settings saved.', 'updated');
    }

    public static function sendHeaders(): void
    {
        if (is_admin()) {
            return;
        }

        $settings = self::getSettings();
        if (is_user_logged_in()) {
            header('Cache-Control: no-store, max-age=0', true);
            return;
        }

        header('Cache-Control: public, max-age=' . (int)$settings['ttl_seconds'] . ', stale-while-revalidate=60', true);
        header('X-AI-Dynamic-Cache-Strategy: ' . self::normalizeStrategy((string)$settings['cache_strategy']), true);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::getSettings();
        settings_errors('ai_wpdyn_modular_messages');
        ?>
        <div class="wrap">
          <h1>AI Dynamic Cache (Modular)</h1>
          <p>Minimal plugin bootstrap for worker-based strategy selection.</p>
          <form method="post">
            <?php wp_nonce_field('ai_wpdyn_modular_save', 'ai_wpdyn_modular_nonce'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label for="worker_base_url">Worker Base URL</label></th>
                <td><input class="regular-text" id="worker_base_url" name="worker_base_url" type="url" value="<?php echo esc_attr((string)$settings['worker_base_url']); ?>" /></td>
              </tr>
              <tr>
                <th scope="row"><label for="plugin_shared_secret">Plugin Shared Secret</label></th>
                <td><input class="regular-text code" id="plugin_shared_secret" name="plugin_shared_secret" type="text" value="<?php echo esc_attr((string)$settings['plugin_shared_secret']); ?>" /></td>
              </tr>
              <tr>
                <th scope="row"><label for="site_id">Site ID</label></th>
                <td><input class="regular-text" id="site_id" name="site_id" type="text" value="<?php echo esc_attr((string)$settings['site_id']); ?>" /></td>
              </tr>
              <tr>
                <th scope="row"><label for="cache_strategy">Cache Strategy</label></th>
                <td>
                  <select id="cache_strategy" name="cache_strategy">
                    <?php foreach (['edge-balanced', 'edge-r2', 'origin-disk', 'object-cache'] as $strategy): ?>
                    <option value="<?php echo esc_attr($strategy); ?>" <?php selected((string)$settings['cache_strategy'], $strategy); ?>><?php echo esc_html($strategy); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="ttl_seconds">TTL (seconds)</label></th>
                <td><input id="ttl_seconds" name="ttl_seconds" type="number" min="30" max="86400" value="<?php echo esc_attr((string)$settings['ttl_seconds']); ?>" /></td>
              </tr>
            </table>
            <p class="submit"><button class="button button-primary" type="submit" name="ai_wpdyn_modular_submit">Save</button></p>
          </form>
        </div>
        <?php
    }

    private static function getSettings(): array
    {
        $defaults = [
            'worker_base_url' => '',
            'plugin_shared_secret' => '',
            'site_id' => self::defaultSiteId(),
            'cache_strategy' => 'edge-balanced',
            'ttl_seconds' => 300,
        ];

        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge($defaults, $stored);
    }

    private static function normalizeStrategy(string $value): string
    {
        $candidate = strtolower(trim($value));
        return in_array($candidate, ['edge-balanced', 'edge-r2', 'origin-disk', 'object-cache'], true)
            ? $candidate
            : 'edge-balanced';
    }

    private static function defaultSiteId(): string
    {
        $host = parse_url(home_url('/'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }
        return 'site-' . substr(md5((string)home_url('/')), 0, 8);
    }
}
