<?php

declare(strict_types=1);

namespace AiWpCache\Cache;

use AiWpCache\Storage\Options;

/**
 * Produces SHA-256 content digests that can be used to detect stale cache entries.
 *
 * Digests are deterministic for the same input so they can be compared across
 * requests without storing state.
 */
final class Digest
{
    public function __construct(private readonly Options $options) {}

    /**
     * Return a SHA-256 hex digest representing a post's current content state.
     *
     * Incorporates post content, selected meta values, and the last-modified date
     * so that any meaningful change to the post produces a different digest.
     */
    public function forPost(int $postId): string
    {
        $post = get_post($postId);

        if ($post === null) {
            return hash('sha256', 'missing:' . $postId);
        }

        $meta     = get_post_meta($postId);
        $metaJson = wp_json_encode($meta) ?: '';

        $fingerprint = implode('|', [
            (string) $post->ID,
            $post->post_content,
            $post->post_modified_gmt,
            $post->post_status,
            $metaJson,
        ]);

        return hash('sha256', $fingerprint);
    }

    /**
     * Return a SHA-256 hex digest for a URL combined with the active policy.
     *
     * Allows detection of stale entries when the caching policy changes.
     */
    public function forPage(string $url): string
    {
        $fingerprint = implode('|', [
            $url,
            $this->options->getActivePolicy(),
            (string) $this->options->getCacheTtl(),
        ]);

        return hash('sha256', $fingerprint);
    }

    /**
     * Return a SHA-256 hex digest representing the current global plugin configuration.
     *
     * Useful for cache busting when plugin options change.
     */
    public function forGlobal(): string
    {
        $fingerprint = implode('|', [
            $this->options->getWorkerUrl(),
            $this->options->getActivePolicy(),
            (string) $this->options->getCacheTtl(),
            (string) $this->options->isEnabled(),
        ]);

        return hash('sha256', $fingerprint);
    }
}
