<?php

declare(strict_types=1);

namespace AiWpCache\Api\Endpoints;

use AiWpCache\Agent\Signer;
use AiWpCache\Api\Client;
use AiWpCache\Cache\OriginDiskCache;
use AiWpCache\Storage\Logger;
use AiWpCache\Storage\Options;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint: POST /wp-json/ai-wp-cache/v1/purge
 *
 * Accepts a tag list or URL, purges the origin disk cache, and propagates
 * the purge to the Cloudflare Worker.
 */
final class Purge
{
    private const NAMESPACE = 'ai-wp-cache/v1';

    public function __construct(
        private readonly Options         $options,
        private readonly Signer          $signer,
        private readonly Client          $client,
        private readonly Logger          $logger,
    ) {}

    /** Register the route with the WordPress REST API. */
    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/purge', [
            'methods'             => 'POST',
            'callback'            => [$this, 'callback'],
            'permission_callback' => [$this, 'permissionCallback'],
        ]);
    }

    /**
     * Allow access when the caller is either:
     * 1. The Cloudflare Worker (valid HMAC signature), or
     * 2. A logged-in user with manage_options capability.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function permissionCallback(WP_REST_Request $request): bool
    {
        // WordPress admin.
        if (current_user_can('manage_options')) {
            return true;
        }

        // Worker HMAC.
        $headers = $this->extractSignatureHeaders($request);
        $url     = rest_url('ai-wp-cache/v1/purge');
        return $this->signer->verify('POST', $url, $request->get_body(), $headers);
    }

    /**
     * Execute the purge.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function callback(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        $tags = [];

        if (!empty($body['tags']) && is_array($body['tags'])) {
            $tags = array_map('sanitize_text_field', $body['tags']);
        } elseif (!empty($body['url'])) {
            // Derive a tag from the URL so single-URL purges work.
            $tags = ['url:' . md5(esc_url_raw((string) $body['url']))];
        }

        if (empty($tags)) {
            return new WP_REST_Response(['error' => 'No tags or URL provided'], 400);
        }

        // Purge origin disk cache.
        $diskCache = new OriginDiskCache();
        $deleted   = 0;
        foreach ($tags as $tag) {
            $deleted += $diskCache->purgeByTag($tag);
        }

        // Propagate to the worker.
        $this->client->purge($tags);

        $this->logger->info('Purge executed via REST', ['tags' => $tags, 'deleted' => $deleted]);

        return new WP_REST_Response(['status' => 'purged', 'tags' => $tags, 'deleted' => $deleted], 200);
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
