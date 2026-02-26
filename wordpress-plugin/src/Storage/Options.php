<?php

declare(strict_types=1);

namespace AiWpCache\Storage;

/**
 * Thin wrapper around WordPress options with a shared prefix and sanitisation.
 */
final class Options
{
    private const PREFIX = 'aiwpc_';

    /**
     * Retrieve an option value.
     *
     * @param string $key     Option key (without prefix).
     * @param mixed  $default Returned when the option is not set.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return get_option(self::PREFIX . $key, $default);
    }

    /**
     * Persist an option value.
     *
     * @param string $key   Option key (without prefix).
     * @param mixed  $value Value to store.
     */
    public function set(string $key, mixed $value): bool
    {
        return update_option(self::PREFIX . $key, $value, false);
    }

    /**
     * Delete an option.
     *
     * @param string $key Option key (without prefix).
     */
    public function delete(string $key): bool
    {
        return delete_option(self::PREFIX . $key);
    }

    // -------------------------------------------------------------------------
    // Typed accessors
    // -------------------------------------------------------------------------

    /** Return the Cloudflare Worker base URL (no trailing slash). */
    public function getWorkerUrl(): string
    {
        $url = (string) $this->get('worker_url', '');
        return rtrim(sanitize_url($url), '/');
    }

    /** Return the raw HMAC secret key. */
    public function getHmacSecret(): string
    {
        return (string) $this->get('hmac_secret', '');
    }

    /** Return the identifier of the currently active policy. */
    public function getActivePolicy(): string
    {
        return (string) $this->get('active_policy', 'default');
    }

    /** Return the default cache TTL in seconds. */
    public function getCacheTtl(): int
    {
        return max(1, (int) $this->get('cache_ttl', 3600));
    }

    /** Whether the plugin caching behaviour is enabled. */
    public function isEnabled(): bool
    {
        return (bool) $this->get('enabled', true);
    }

    // -------------------------------------------------------------------------
    // Typed mutators with sanitisation
    // -------------------------------------------------------------------------

    /** Persist the Worker URL after sanitisation. */
    public function setWorkerUrl(string $url): bool
    {
        return $this->set('worker_url', sanitize_url(trim($url)));
    }

    /**
     * Persist the HMAC secret.
     *
     * The value is stored as-is; the caller is responsible for generating a
     * sufficiently random secret (≥ 32 bytes recommended).
     */
    public function setHmacSecret(string $secret): bool
    {
        return $this->set('hmac_secret', sanitize_text_field($secret));
    }

    /** Persist the active policy identifier. */
    public function setActivePolicy(string $policy): bool
    {
        $allowed = ['default', 'disk_only', 'disk_edge', 'r2', 'full'];
        $policy  = in_array($policy, $allowed, true) ? $policy : 'default';
        return $this->set('active_policy', $policy);
    }
}
