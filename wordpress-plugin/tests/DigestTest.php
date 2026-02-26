<?php

declare(strict_types=1);

namespace AiWpCache\Tests;

use AiWpCache\Cache\Digest;
use AiWpCache\Storage\Options;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AiWpCache\Cache\Digest.
 *
 * WordPress function stubs (get_post, get_post_meta, wp_json_encode) are
 * provided by tests/bootstrap.php.
 */

final class DigestTest extends TestCase
{
    private Digest $digest;

    protected function setUp(): void
    {
        $this->digest = new Digest(new Options());
    }

    /** forPage() must return a 64-character lowercase hex string (SHA-256). */
    public function testForPageReturnsSha256(): void
    {
        $result = $this->digest->forPage('https://example.com/hello-world/');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    /** forPage() must be deterministic: same URL → same digest. */
    public function testSameInputsSameDigest(): void
    {
        $url    = 'https://example.com/same-url/';
        $first  = $this->digest->forPage($url);
        $second = $this->digest->forPage($url);

        $this->assertSame($first, $second);
    }

    /** forPage() must produce different digests for different URLs. */
    public function testDifferentUrlsDifferentDigests(): void
    {
        $digestA = $this->digest->forPage('https://example.com/page-a/');
        $digestB = $this->digest->forPage('https://example.com/page-b/');

        $this->assertNotSame($digestA, $digestB);
    }

    /** forGlobal() must return a 64-character lowercase hex string (SHA-256). */
    public function testForGlobalReturnsSha256(): void
    {
        $result = $this->digest->forGlobal();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    /** forPost() returns a 64-char SHA-256 for an existing post. */
    public function testForPostReturnsSha256(): void
    {
        $result = $this->digest->forPost(42);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    /** forPost() is deterministic: same post ID → same digest. */
    public function testForPostIsDeterministic(): void
    {
        $first  = $this->digest->forPost(7);
        $second = $this->digest->forPost(7);

        $this->assertSame($first, $second);
    }

    /** forPost() returns a valid SHA-256 even when the post does not exist. */
    public function testForPostMissingPostReturnsSha256(): void
    {
        // Post ID 0 triggers the null branch in our stub.
        $result = $this->digest->forPost(0);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }
}
