<?php

declare(strict_types=1);

namespace AiWpCache\Agent;

use RuntimeException;

/**
 * Signs and verifies outgoing/incoming HTTP requests using HMAC-SHA256.
 *
 * Canonical string format:
 *   METHOD\nURL\nTIMESTAMP\nNONCE\nBODY_HASH
 */
final class Signer
{
    /** Maximum age of a valid request in seconds. */
    private const MAX_AGE = 300;

    /** Nonce transient TTL in seconds (must be > MAX_AGE). */
    private const NONCE_TTL = 600;

    /** @param string $secret Shared HMAC secret (hex or raw). */
    public function __construct(private readonly string $secret) {}

    /**
     * Generate signed headers for an outgoing request.
     *
     * @param string $method HTTP method (uppercase).
     * @param string $url    Full request URL.
     * @param string $body   Raw request body (empty string for GET).
     *
     * @return array{X-Timestamp: string, X-Nonce: string, X-Signature: string}
     *
     * @throws RuntimeException When random_bytes() fails.
     */
    public function sign(string $method, string $url, string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $signature = $this->computeSignature($method, $url, $timestamp, $nonce, $body);

        return [
            'X-Timestamp' => $timestamp,
            'X-Nonce'     => $nonce,
            'X-Signature' => $signature,
        ];
    }

    /**
     * Verify a signed incoming request.
     *
     * Returns false when:
     * - Any required header is missing.
     * - The timestamp is outside the MAX_AGE window.
     * - The nonce has been seen before (replay attack).
     * - The signature does not match.
     *
     * @param string               $method  HTTP method (uppercase).
     * @param string               $url     Full request URL.
     * @param string               $body    Raw request body.
     * @param array<string, mixed> $headers Associative header map (case-insensitive keys expected).
     */
    public function verify(string $method, string $url, string $body, array $headers): bool
    {
        // Normalise header keys to lowercase for reliable lookup.
        $headers = array_change_key_case($headers, CASE_LOWER);

        $timestamp = (string) ($headers['x-timestamp'] ?? '');
        $nonce     = (string) ($headers['x-nonce']     ?? '');
        $signature = (string) ($headers['x-signature'] ?? '');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }

        // Reject stale requests.
        $age = abs(time() - (int) $timestamp);
        if ($age > self::MAX_AGE) {
            return false;
        }

        // Replay-attack guard via WordPress transients.
        $transientKey = 'aiwpc_nonce_' . $nonce;
        if (get_transient($transientKey) !== false) {
            return false;
        }
        set_transient($transientKey, 1, self::NONCE_TTL);

        // Constant-time comparison.
        $expected = $this->computeSignature($method, $url, $timestamp, $nonce, $body);
        return hash_equals($expected, $signature);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Compute the HMAC-SHA256 signature for a canonical string. */
    private function computeSignature(
        string $method,
        string $url,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        $bodyHash  = hash('sha256', $body);
        $canonical = implode("\n", [strtoupper($method), $url, $timestamp, $nonce, $bodyHash]);
        return hash_hmac('sha256', $canonical, $this->secret);
    }
}
