<?php

declare(strict_types=1);

namespace AiWpCache\Cache;

/**
 * Origin disk cache that stores serialised response fragments on the local
 * filesystem under wp-content/cache/ai-wp-cache/.
 *
 * Each cache entry is stored as a plain file whose name is derived from the
 * SHA-256 of the cache key.  A sidecar metadata file (same name + ".meta.json")
 * records the original key, associated tags, TTL, and creation timestamp so
 * that tag-based purges can locate entries without scanning file content.
 */
final class OriginDiskCache
{
    private readonly string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir
            ?? (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/ai-wp-cache' : sys_get_temp_dir() . '/ai-wp-cache');
    }

    /**
     * Retrieve a cached value.
     *
     * Returns null when the entry does not exist or has expired.
     */
    public function get(string $key): ?string
    {
        [$dataFile, $metaFile] = $this->filePaths($key);

        if (!is_file($metaFile) || !is_file($dataFile)) {
            return null;
        }

        $meta = $this->readMeta($metaFile);
        if ($meta === null) {
            return null;
        }

        // Check TTL.
        if (isset($meta['ttl'], $meta['created_at'])
            && (time() - (int) $meta['created_at']) > (int) $meta['ttl']
        ) {
            $this->deleteFiles($dataFile, $metaFile);
            return null;
        }

        $value = file_get_contents($dataFile);
        return $value !== false ? $value : null;
    }

    /**
     * Store a value in the disk cache.
     *
     * @param string       $key   Cache key.
     * @param string       $value Serialised value to store.
     * @param int          $ttl   TTL in seconds.
     * @param list<string> $tags  Cache tags associated with this entry.
     */
    public function set(string $key, string $value, int $ttl, array $tags = []): void
    {
        $this->ensureDir();
        [$dataFile, $metaFile] = $this->filePaths($key);

        file_put_contents($dataFile, $value, LOCK_EX);

        $meta = [
            'key'        => $key,
            'tags'       => $tags,
            'ttl'        => $ttl,
            'created_at' => time(),
        ];

        file_put_contents($metaFile, (string) wp_json_encode($meta), LOCK_EX);
    }

    /**
     * Delete a single cache entry by key.
     */
    public function delete(string $key): void
    {
        [$dataFile, $metaFile] = $this->filePaths($key);
        $this->deleteFiles($dataFile, $metaFile);
    }

    /**
     * Purge all cache entries that carry the given tag.
     *
     * @return int Number of entries deleted.
     */
    public function purgeByTag(string $tag): int
    {
        if (!is_dir($this->cacheDir)) {
            return 0;
        }

        $deleted = 0;

        /** @var \SplFileInfo $fileInfo */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS)
        ) as $fileInfo) {
            if (!str_ends_with($fileInfo->getFilename(), '.meta.json')) {
                continue;
            }

            $metaFile = $fileInfo->getPathname();
            $meta     = $this->readMeta($metaFile);

            if ($meta === null || !isset($meta['tags']) || !is_array($meta['tags'])) {
                continue;
            }

            if (in_array($tag, $meta['tags'], true)) {
                $dataFile = substr($metaFile, 0, -strlen('.meta.json'));
                $this->deleteFiles($dataFile, $metaFile);
                ++$deleted;
            }
        }

        return $deleted;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Derive the data and metadata file paths for a cache key.
     *
     * @return array{string, string} [dataFile, metaFile]
     */
    private function filePaths(string $key): array
    {
        $hash     = hash('sha256', $key);
        $subDir   = $this->cacheDir . '/' . substr($hash, 0, 2);
        $dataFile = $subDir . '/' . $hash;
        $metaFile = $dataFile . '.meta.json';
        return [$dataFile, $metaFile];
    }

    /**
     * Decode a metadata JSON file.
     *
     * @return array<string, mixed>|null
     */
    private function readMeta(string $metaFile): ?array
    {
        $raw = file_get_contents($metaFile);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Delete data and meta files, ignoring missing-file errors. */
    private function deleteFiles(string $dataFile, string $metaFile): void
    {
        if (is_file($dataFile)) {
            @unlink($dataFile);
        }
        if (is_file($metaFile)) {
            @unlink($metaFile);
        }
    }

    /** Create the cache directory if it doesn't exist. */
    private function ensureDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            wp_mkdir_p($this->cacheDir);
        }
    }
}
