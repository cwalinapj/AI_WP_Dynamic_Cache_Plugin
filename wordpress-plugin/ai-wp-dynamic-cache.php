<?php
/**
 * Plugin Name: AI WP Dynamic Cache
 * Plugin URI:  https://github.com/cwalinapj/AI_WP_Dynamic_Cache_Plugin
 * Description: Signed edge agent cache plugin with Cloudflare Workers control plane.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author:      AI WP Plugin Family
 * License:     MIT
 * Text Domain: ai-wp-dynamic-cache
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const AIWPC_VERSION   = '1.0.0';
const AIWPC_FILE      = __FILE__;
const AIWPC_DIR       = __DIR__;
const AIWPC_BASENAME  = 'ai-wp-dynamic-cache/ai-wp-dynamic-cache.php';

/** Require PHP 8.1+. */
if (PHP_VERSION_ID < 80100) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('AI WP Dynamic Cache requires PHP 8.1 or higher.', 'ai-wp-dynamic-cache')
            . '</p></div>';
    });
    return;
}

/** PSR-4 autoloader for the AiWpCache namespace. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'AiWpCache\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

/** Bootstrap the plugin. */
$plugin = new AiWpCache\Plugin();

register_activation_hook(__FILE__, [$plugin, 'activate']);
register_deactivation_hook(__FILE__, [$plugin, 'deactivate']);
