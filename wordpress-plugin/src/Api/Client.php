<?php

declare(strict_types=1);

namespace AiWpCache\Api;

use AiWpCache\Agent\Signer;
use AiWpCache\Storage\Logger;
use AiWpCache\Storage\Options;

/**
 * HTTP client that communicates with the Cloudflare Worker control plane.
 *
 * Every request is signed with HMAC-SHA256 and sent via WordPress's HTTP API
 * (wp_remote_post / wp_remote_get).
 */
final class Client
{
    private const TIMEOUT = 10; // seconds

    public function __construct(
        private readonly Options $options,
        private readonly Signer  $signer,
        private readonly Logger  $logger,
    ) {}

    /**
     * Ask the worker to purge cache entries for the given tags.
     *
     * @param list<string> $tags Surrogate-Key values to purge.
     */
    public function purge(array $tags): bool
    {
        $body = (string) wp_json_encode(['tags' => $tags]);
        return $this->post('/api/purge', $body);
    }

    /**
     * Ask the worker to preload the given URLs.
     *
     * @param list<string> $urls Fully-qualified URLs to preload.
     */
    public function preload(array $urls): bool
    {
        $body = (string) wp_json_encode(['urls' => $urls]);
        return $this->post('/api/preload', $body);
    }

    /**
     * Ping the worker heartbeat endpoint.
     *
     * Returns true when the worker responds with HTTP 200.
     */
    public function heartbeat(): bool
    {
        $workerUrl = $this->options->getWorkerUrl();
        if ($workerUrl === '') {
            return false;
        }

        $url     = $workerUrl . '/api/heartbeat';
        $headers = $this->signer->sign('GET', $url);

        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Heartbeat request failed', ['error' => $response->get_error_message()]);
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Send one or more telemetry/signal payloads to the worker.
     *
     * @param list<array<string, mixed>> $signals
     */
    public function sendSignals(array $signals): bool
    {
        $body = (string) wp_json_encode(['signals' => $signals]);
        return $this->post('/api/signals', $body);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Execute a signed POST request against the worker.
     *
     * @param string $path Relative path (must start with /).
     * @param string $body JSON-encoded request body.
     */
    private function post(string $path, string $body): bool
    {
        $workerUrl = $this->options->getWorkerUrl();
        if ($workerUrl === '') {
            $this->logger->warn('Worker URL not configured – skipping request', ['path' => $path]);
            return false;
        }

        $url     = $workerUrl . $path;
        $headers = array_merge(
            $this->signer->sign('POST', $url, $body),
            ['Content-Type' => 'application/json']
        );

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => $body,
            'timeout' => self::TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('POST request failed', [
                'path'  => $path,
                'error' => $response->get_error_message(),
            ]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $this->logger->warn('Unexpected response from worker', [
                'path' => $path,
                'code' => $code,
            ]);
            return false;
        }

        return true;
    }
}
