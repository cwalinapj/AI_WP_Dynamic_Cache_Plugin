<?php

declare(strict_types=1);

namespace AiWpCache\Tests;

use AiWpCache\Agent\Signer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AiWpCache\Agent\Signer.
 *
 * WordPress transient stubs are provided by tests/bootstrap.php.
 */

final class SignerTest extends TestCase
{
    private Signer $signer;

    protected function setUp(): void
    {
        $GLOBALS['_aiwpc_test_transients'] = [];
        $this->signer = new Signer('super-secret-key-for-testing-1234');
    }

    /** sign() must return an array containing all three required header keys. */
    public function testSignProducesRequiredHeaders(): void
    {
        $headers = $this->signer->sign('POST', 'https://example.com/api/purge', '{"tags":["site"]}');

        $this->assertArrayHasKey('X-Timestamp', $headers);
        $this->assertArrayHasKey('X-Nonce', $headers);
        $this->assertArrayHasKey('X-Signature', $headers);
    }

    /** A freshly signed request must verify successfully. */
    public function testSignatureIsValid(): void
    {
        $method = 'POST';
        $url    = 'https://example.com/api/purge';
        $body   = '{"tags":["site"]}';

        $headers = $this->signer->sign($method, $url, $body);

        // Lowercase keys to simulate HTTP request parsing.
        $normalised = array_change_key_case($headers, CASE_LOWER);

        // The nonce transient must not pre-exist.
        unset($GLOBALS['_aiwpc_test_transients']['aiwpc_nonce_' . $normalised['x-nonce']]);

        $result = $this->signer->verify($method, $url, $body, $normalised);
        $this->assertTrue($result);
    }

    /** A request with an old timestamp (> 300 s) must be rejected. */
    public function testExpiredTimestampIsRejected(): void
    {
        $method = 'POST';
        $url    = 'https://example.com/api/purge';
        $body   = '';
        $nonce  = bin2hex(random_bytes(16));

        // Craft headers manually with a stale timestamp.
        $oldTimestamp = (string) (time() - 301);
        $canonical    = implode("\n", [
            strtoupper($method),
            $url,
            $oldTimestamp,
            $nonce,
            hash('sha256', $body),
        ]);
        $signature = hash_hmac('sha256', $canonical, 'super-secret-key-for-testing-1234');

        $headers = [
            'x-timestamp' => $oldTimestamp,
            'x-nonce'     => $nonce,
            'x-signature' => $signature,
        ];

        $result = $this->signer->verify($method, $url, $body, $headers);
        $this->assertFalse($result);
    }

    /** A nonce that has already been consumed must be rejected (replay protection). */
    public function testReplayedNonceIsRejected(): void
    {
        $method = 'POST';
        $url    = 'https://example.com/api/purge';
        $body   = '{"tags":["post:1"]}';

        $headers    = $this->signer->sign($method, $url, $body);
        $normalised = array_change_key_case($headers, CASE_LOWER);

        // First verification consumes the nonce.
        $first = $this->signer->verify($method, $url, $body, $normalised);
        $this->assertTrue($first, 'First verification should succeed');

        // Second verification with the same nonce must fail.
        $second = $this->signer->verify($method, $url, $body, $normalised);
        $this->assertFalse($second, 'Replayed nonce must be rejected');
    }

    /** A request signed with a different secret must not verify. */
    public function testDifferentSecretIsRejected(): void
    {
        $method    = 'GET';
        $url       = 'https://example.com/api/heartbeat';
        $body      = '';
        $signerA   = new Signer('secret-A-1234567890abcdef');
        $signerB   = new Signer('secret-B-1234567890abcdef');

        $headers    = $signerA->sign($method, $url, $body);
        $normalised = array_change_key_case($headers, CASE_LOWER);

        // Clear the nonce transient so replay protection does not interfere.
        unset($GLOBALS['_aiwpc_test_transients']['aiwpc_nonce_' . $normalised['x-nonce']]);

        $result = $signerB->verify($method, $url, $body, $normalised);
        $this->assertFalse($result);
    }
}
