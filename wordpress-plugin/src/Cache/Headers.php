<?php

declare(strict_types=1);

namespace AiWpCache\Cache;

/**
 * Manages HTTP cache-control and surrogate-key headers for frontend responses.
 */
final class Headers
{
    /**
     * Set public caching headers for a cacheable page.
     *
     * @param int          $ttl  Cache time-to-live in seconds.
     * @param list<string> $tags Surrogate-Key / cache-tag values.
     */
    public function setForPage(int $ttl, array $tags = []): void
    {
        $ttl = max(1, $ttl);

        header(sprintf('Cache-Control: public, max-age=%d, s-maxage=%d', $ttl, $ttl));
        header(sprintf('CDN-Cache-Control: max-age=%d', $ttl));

        if (!empty($tags)) {
            header('Surrogate-Key: ' . implode(' ', array_map([$this, 'sanitiseTagForHeader'], $tags)));
        }
    }

    /**
     * Set headers to prevent any caching.
     *
     * Suitable for logged-in or dynamic responses that must never be cached.
     */
    public function setNoCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    /**
     * Set headers to mark a response as private / bypass CDN caching.
     *
     * Used for authenticated or personalised responses.
     */
    public function setBypass(): void
    {
        header('Cache-Control: private, no-store');
    }

    /**
     * Determine whether the current request should bypass the cache.
     *
     * Returns true when ANY of the following is detected:
     * - Logged-in WordPress user.
     * - HTTP POST request.
     * - WooCommerce cart or checkout page.
     * - Admin bar visible (implies is_user_logged_in()).
     * - Common no-cache bypass cookies present.
     */
    public function isBypass(): bool
    {
        // Logged-in user.
        if (is_user_logged_in()) {
            return true;
        }

        // POST (or other non-idempotent) request.
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return true;
        }

        // WooCommerce-specific bypass.
        if ($this->isWooCommerceBypass()) {
            return true;
        }

        // Common bypass cookie names.
        $bypassCookies = [
            'wordpress_logged_in_',
            'woocommerce_cart_hash',
            'woocommerce_items_in_cart',
            'wp-postpass_',
        ];

        foreach ($bypassCookies as $cookiePrefix) {
            foreach (array_keys($_COOKIE) as $cookieName) {
                if (str_starts_with((string) $cookieName, $cookiePrefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Strip characters not allowed in Surrogate-Key header values. */
    private function sanitiseTagForHeader(string $tag): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_:.]/', '-', $tag) ?? $tag;
    }

    /** Returns true when the request targets a WooCommerce cart or checkout. */
    private function isWooCommerceBypass(): bool
    {
        if (!function_exists('is_cart') || !function_exists('is_checkout')) {
            return false;
        }
        return is_cart() || is_checkout();
    }
}
