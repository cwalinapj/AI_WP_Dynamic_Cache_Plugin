<?php

declare(strict_types=1);

namespace AiWpCache\Api\Endpoints;

use AiWpCache\Agent\Signer;
use AiWpCache\Storage\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint: POST /wp-json/ai-wp-cache/v1/signals
 *
 * Receives cache-hit / cache-miss / telemetry signals from the Cloudflare
 * Worker. All requests must carry a valid HMAC signature.
 */
final class Signals
{
    private const NAMESPACE = 'ai-wp-cache/v1';

    public function __construct(
        private readonly Signer $signer,
        private readonly Logger $logger,
    ) {}

    /** Register the route with the WordPress REST API. */
    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/signals', [
            'methods'             => 'POST',
            'callback'            => [$this, 'callback'],
            'permission_callback' => [$this, 'permissionCallback'],
        ]);
    }

    /**
     * Verify the HMAC signature before granting access.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function permissionCallback(WP_REST_Request $request): bool
    {
        $headers = $this->extractSignatureHeaders($request);
        $url     = rest_url('ai-wp-cache/v1/signals');
        $body    = $request->get_body();

        return $this->signer->verify('POST', $url, $body, $headers);
    }

    /**
     * Process an inbound signal payload.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function callback(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        $type      = isset($body['type']) ? sanitize_text_field((string) $body['type']) : 'unknown';
        $url       = isset($body['url'])  ? esc_url_raw((string) $body['url'])          : '';
        $timestamp = isset($body['timestamp']) ? (int) $body['timestamp']               : time();

        $this->logger->info('Signal received', [
            'type'      => $type,
            'url'       => $url,
            'timestamp' => $timestamp,
        ]);

        return new WP_REST_Response(['status' => 'accepted'], 202);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract HMAC signature headers from the REST request.
     *
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
