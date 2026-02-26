<?php

declare(strict_types=1);

namespace AiWpCache\Admin;

use AiWpCache\Admin\Pages\Dashboard;
use AiWpCache\Admin\Pages\Experiments;
use AiWpCache\Admin\Pages\Logs;
use AiWpCache\Admin\Pages\Policies;
use AiWpCache\Api\Client;
use AiWpCache\Storage\Logger;
use AiWpCache\Storage\Options;

/**
 * Registers the top-level admin menu and all sub-menu pages.
 */
final class Menu
{
    /** Capability required to access all plugin admin pages. */
    private const CAP = 'manage_options';

    /** Slug for the top-level menu item. */
    private const SLUG = 'ai-wp-cache';

    public function __construct(
        private readonly Options $options,
        private readonly Logger  $logger,
        private readonly Client  $client,
    ) {}

    /** Register all admin menu entries and asset hooks. */
    public function register(): void
    {
        add_menu_page(
            __('AI Cache', 'ai-wp-dynamic-cache'),
            __('AI Cache', 'ai-wp-dynamic-cache'),
            self::CAP,
            self::SLUG,
            [$this, 'renderDashboard'],
            'dashicons-performance',
            80
        );

        add_submenu_page(
            self::SLUG,
            __('Dashboard', 'ai-wp-dynamic-cache'),
            __('Dashboard', 'ai-wp-dynamic-cache'),
            self::CAP,
            self::SLUG,
            [$this, 'renderDashboard']
        );

        add_submenu_page(
            self::SLUG,
            __('Policies', 'ai-wp-dynamic-cache'),
            __('Policies', 'ai-wp-dynamic-cache'),
            self::CAP,
            self::SLUG . '-policies',
            [$this, 'renderPolicies']
        );

        add_submenu_page(
            self::SLUG,
            __('Experiments', 'ai-wp-dynamic-cache'),
            __('Experiments', 'ai-wp-dynamic-cache'),
            self::CAP,
            self::SLUG . '-experiments',
            [$this, 'renderExperiments']
        );

        add_submenu_page(
            self::SLUG,
            __('Logs', 'ai-wp-dynamic-cache'),
            __('Logs', 'ai-wp-dynamic-cache'),
            self::CAP,
            self::SLUG . '-logs',
            [$this, 'renderLogs']
        );

        // Enqueue assets only on plugin pages.
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    // -------------------------------------------------------------------------
    // Page renderers (delegate to page classes)
    // -------------------------------------------------------------------------

    public function renderDashboard(): void
    {
        (new Dashboard($this->options, $this->logger, $this->client))->render();
    }

    public function renderPolicies(): void
    {
        (new Policies($this->options))->render();
    }

    public function renderExperiments(): void
    {
        (new Experiments($this->options))->render();
    }

    public function renderLogs(): void
    {
        (new Logs($this->logger))->render();
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    /** Enqueue CSS and JS only on this plugin's admin pages. */
    public function enqueueAssets(string $hookSuffix): void
    {
        $pluginPages = [
            'toplevel_page_' . self::SLUG,
            'ai-cache_page_' . self::SLUG . '-policies',
            'ai-cache_page_' . self::SLUG . '-experiments',
            'ai-cache_page_' . self::SLUG . '-logs',
        ];

        if (!in_array($hookSuffix, $pluginPages, true)) {
            return;
        }

        wp_enqueue_style(
            'aiwpc-admin',
            plugins_url('assets/admin.css', AIWPC_FILE),
            [],
            AIWPC_VERSION
        );

        wp_enqueue_script(
            'aiwpc-admin',
            plugins_url('assets/admin.js', AIWPC_FILE),
            ['jquery'],
            AIWPC_VERSION,
            true
        );

        wp_localize_script('aiwpc-admin', 'aiwpcData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('aiwpc_admin'),
            'restUrl' => rest_url('ai-wp-cache/v1'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}
