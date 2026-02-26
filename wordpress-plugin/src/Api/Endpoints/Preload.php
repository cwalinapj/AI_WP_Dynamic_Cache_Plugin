<?php

declare(strict_types=1);

namespace AiWpCache\Api\Endpoints;

use AiWpCache\Agent\Signer;
use AiWpCache\Api\Client;
use AiWpCache\Storage\Logger;
use AiWpCache\Storage\Options;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint: POST /wp-json/ai-wp-cache/v1/preload
 *
 * Queues URLs for background preloading using WordPress cron.
 */
final class Preload
{
    private const NAMESPACE = 'ai-wp-cache/v1';

    /** Accepted priority values and their cron delay in seconds. */
    private const PRIORITY_DELAY = [
        'high'   => 0,
        'normal' => 30,
        'low'    => 120,
    ];

    public function __construct(
        private readonly Options $options,
        private readonly Signer  $signer,
        private readonly Client  $client,
        private readonly Logger  $logger,
    ) {}

    /** Register the route with the WordPress REST API. */
    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/preload', [
            'methods'             => 'POST',
            'callback'            => [$this, 'callback'],
            'permission_callback' => [$this, 'permissionCallback'],
        ]);
    }

    /**
     * Allow access when the caller is the Worker (HMAC) or an admin.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function permissionCallback(WP_REST_Request $request): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $headers = $this->extractSignatureHeaders($request);
        $url     = rest_url('ai-wp-cache/v1/preload');
        return $this->signer->verify('POST', $url, $request->get_body(), $headers);
    }

    /**
     * Queue the requested URLs for preloading.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function callback(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        if (empty($body['urls']) || !is_array($body['urls'])) {
            return new WP_REST_Response(['error' => 'urls array required'], 400);
        }

        $urls     = array_map('esc_url_raw', $body['urls']);
        $urls     = array_filter($urls, static fn(string $u): bool => $u !== '');
        $priority = isset($body['priority']) && array_key_exists($body['priority'], self::PRIORITY_DELAY)
            ? (string) $body['priority']
            : 'normal';

        $delay = self::PRIORITY_DELAY[$priority];

        foreach ($urls as $url) {
            wp_schedule_single_event(time() + $delay, 'aiwpc_preload_url', [$url]);
        }

        $this->logger->info('Preload queued', ['urls' => count($urls), 'priority' => $priority]);

        return new WP_REST_Response([
            'status'   => 'queued',
            'urls'     => count($urls),
            'priority' => $priority,
        ], 202);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     * @return array<string, string>
     */
    private function extractSignatureHeaders(WP_REST_Request $request): array
    {
        return [
            'x-timestamp' => $request->get_header('x-timestamp') ?? '',
            'x-nonce'     => $request->get_header('x-nonce')     ?? '',
            'x-signature' => $request->get_header('x-signature') ?? '',
        ];
    }
}
