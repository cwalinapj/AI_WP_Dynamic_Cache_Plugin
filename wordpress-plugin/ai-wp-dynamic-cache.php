<?php
/**
 * Plugin Name: AI WP Dynamic Cache (Modular)
 * Description: Modular bootstrap for dynamic cache + sandbox benchmark integration.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/Plugin.php';

add_action('plugins_loaded', static function () {
    \AI\WPDynamicCache\Plugin::bootstrap();
});
