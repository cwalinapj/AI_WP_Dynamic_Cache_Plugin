<?php

declare(strict_types=1);

namespace AiWpCache\Api\Endpoints;

use AiWpCache\Storage\Options;
use WP_REST_Response;

/**
 * REST endpoint: GET /wp-json/ai-wp-cache/v1/heartbeat
 *
 * Returns plugin/worker status without requiring authentication so that
 * monitoring tools and the Cloudflare Worker can probe liveness cheaply.
 */
final class Heartbeat
{
    private const NAMESPACE = 'ai-wp-cache/v1';

    public function __construct(private readonly Options $options) {}

    /** Register the route with the WordPress REST API. */
    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/heartbeat', [
            'methods'             => 'GET',
            'callback'            => [$this, 'callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** Handle the heartbeat request. */
    public function callback(): WP_REST_Response
    {
        return new WP_REST_Response([
            'status'     => 'ok',
            'version'    => AIWPC_VERSION,
            'worker_url' => $this->options->getWorkerUrl(),
        ], 200);
    }
}
