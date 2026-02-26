<?php

declare(strict_types=1);

namespace AiWpCache;

use AiWpCache\Admin\Menu;
use AiWpCache\Agent\Hooks\ContentChange;
use AiWpCache\Agent\Hooks\TemplateSignals;
use AiWpCache\Agent\Signer;
use AiWpCache\Api\Client;
use AiWpCache\Api\Endpoints\Experiments;
use AiWpCache\Api\Endpoints\Heartbeat;
use AiWpCache\Api\Endpoints\Preload;
use AiWpCache\Api\Endpoints\Purge;
use AiWpCache\Api\Endpoints\Signals;
use AiWpCache\Cache\Headers;
use AiWpCache\Cache\Tags;
use AiWpCache\Storage\Logger;
use AiWpCache\Storage\Options;

/**
 * Main plugin bootstrap class.
 *
 * Instantiated once from the plugin entry point. Registers all hooks,
 * REST endpoints, and admin pages.
 */
final class Plugin
{
    private Options $options;
    private Logger  $logger;
    private Client  $client;
    private Signer  $signer;

    public function __construct()
    {
        $this->options = new Options();
        $this->logger  = new Logger();
        $this->signer  = new Signer($this->options->getHmacSecret());
        $this->client  = new Client($this->options, $this->signer, $this->logger);

        $this->registerHooks();
    }

    /** Wire up all WordPress hooks. */
    private function registerHooks(): void
    {
        // REST API endpoints.
        add_action('rest_api_init', function (): void {
            (new Heartbeat($this->options))->register();
            (new Signals($this->signer, $this->logger))->register();
            (new Purge($this->options, $this->signer, $this->client, $this->logger))->register();
            (new Preload($this->options, $this->signer, $this->client, $this->logger))->register();
            (new Experiments($this->options, $this->logger))->register();
        });

        // Admin menu.
        if (is_admin()) {
            add_action('admin_menu', function (): void {
                (new Menu($this->options, $this->logger, $this->client))->register();
            });
        }

        // Cache behaviour hooks (only on frontend).
        if (!is_admin()) {
            $tags    = new Tags();
            $headers = new Headers();

            (new ContentChange($this->options, $this->client, $this->logger, $tags))->register();
            (new TemplateSignals($this->options, $headers, $tags, $this->logger))->register();
        }
    }

    /** Runs on plugin activation: creates DB tables / sets defaults. */
    public function activate(): void
    {
        if (!$this->options->get('version')) {
            $this->options->set('version', AIWPC_VERSION);
            $this->options->set('enabled', true);
            $this->options->set('cache_ttl', 3600);
        }

        $this->logger->info('Plugin activated', ['version' => AIWPC_VERSION]);
    }

    /** Runs on plugin deactivation: cleans up scheduled events. */
    public function deactivate(): void
    {
        wp_clear_scheduled_hook('aiwpc_preload_url');
        $this->logger->info('Plugin deactivated');
    }
}
