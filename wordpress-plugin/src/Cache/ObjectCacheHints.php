<?php

declare(strict_types=1);

namespace AiWpCache\Cache;

/**
 * Hints and helpers for WordPress object-cache integration.
 *
 * Provides guidance on which object-cache groups should be bypassed for
 * correctness and offers a bulk pre-warm utility for post pages.
 */
final class ObjectCacheHints
{
    /**
     * Groups that should NOT be served from a persistent object cache.
     *
     * These groups contain ephemeral, user-specific, or quickly-changing data
     * that would cause correctness issues if served stale.
     */
    private const BYPASS_GROUPS = [
        'session',
        'woocommerce_transient',
        'user_meta',
        'users',
        'user_emails',
        'useremail',
        'userlogins',
        'userslugs',
    ];

    /**
     * Determine whether the given object-cache group should be bypassed.
     *
     * @param string $group Object cache group name.
     */
    public function shouldBypassObjectCache(string $group): bool
    {
        return in_array(strtolower($group), self::BYPASS_GROUPS, true);
    }

    /**
     * Pre-warm the WordPress object cache with posts and their meta.
     *
     * Executes two batched queries instead of N individual queries, which
     * significantly reduces database round-trips on archive pages.
     *
     * @param list<int> $postIds Post IDs to prefetch.
     */
    public function prefillObjectCache(array $postIds): void
    {
        if (empty($postIds)) {
            return;
        }

        $postIds = array_map('intval', $postIds);
        $postIds = array_filter($postIds, static fn(int $id): bool => $id > 0);

        if (empty($postIds)) {
            return;
        }

        // Bulk-fetch posts; WP_Query populates wp_cache internally.
        $cachedIds = [];
        foreach ($postIds as $id) {
            $cached = wp_cache_get($id, 'posts');
            if ($cached !== false) {
                $cachedIds[] = $id;
            }
        }

        $missing = array_diff($postIds, $cachedIds);

        if (!empty($missing)) {
            // Fetch posts and let WordPress cache them automatically.
            new \WP_Query([
                'post__in'               => $missing,
                'posts_per_page'         => count($missing),
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
                'ignore_sticky_posts'    => true,
            ]);
        }

        // Prefill post-meta for any posts not already in cache.
        $this->prefillPostMeta($missing);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Ensure post-meta for the given IDs is in the object cache.
     *
     * @param list<int> $postIds
     */
    private function prefillPostMeta(array $postIds): void
    {
        if (empty($postIds)) {
            return;
        }

        $uncached = array_filter(
            $postIds,
            static fn(int $id): bool => wp_cache_get($id, 'post_meta') === false
        );

        if (!empty($uncached)) {
            update_meta_cache('post', $uncached);
        }
    }
}
