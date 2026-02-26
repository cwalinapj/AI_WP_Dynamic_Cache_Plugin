<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: provides WordPress function stubs so the plugin classes
 * can be tested without loading a full WordPress installation.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Global WordPress stubs
// ---------------------------------------------------------------------------

/** @var array<string, mixed> In-memory transient store. */
$GLOBALS['_aiwpc_test_transients'] = [];

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        return $GLOBALS['_aiwpc_test_transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['_aiwpc_test_transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset($GLOBALS['_aiwpc_test_transients'][$key]);
        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($data, $flags, $depth);
    }
}

if (!function_exists('get_post')) {
    function get_post(int $postId): ?\stdClass
    {
        if ($postId === 0) {
            return null;
        }

        $post                    = new \stdClass();
        $post->ID                = $postId;
        $post->post_content      = 'Content for post ' . $postId;
        $post->post_modified_gmt = '2024-01-01 00:00:00';
        $post->post_status       = 'publish';

        return $post;
    }
}

if (!function_exists('get_option')) {
    /** @var array<string, mixed> */
    $GLOBALS['_aiwpc_test_options'] = [
        'aiwpc_worker_url'    => 'https://worker.example.com',
        'aiwpc_active_policy' => 'disk_edge',
        'aiwpc_cache_ttl'     => 3600,
        'aiwpc_enabled'       => true,
    ];

    function get_option(string $option, mixed $default = false): mixed
    {
        return $GLOBALS['_aiwpc_test_options'][$option] ?? $default;
    }
}

if (!function_exists('sanitize_url')) {
    function sanitize_url(string $url): string { return $url; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string { return trim($str); }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        $meta = ['_test_meta' => ['value_' . $postId]];
        if ($key !== '') {
            return $single ? ($meta[$key][0] ?? '') : ($meta[$key] ?? []);
        }
        return $meta;
    }
}
