<?php

declare(strict_types=1);

namespace AiWpCache\Storage;

/**
 * Circular-buffer logger backed by a single WordPress option.
 *
 * Entries are stored as a JSON-encoded array with a configurable maximum size.
 * When the buffer is full the oldest entries are discarded.
 */
final class Logger
{
    private const OPTION_KEY = 'aiwpc_logs';
    private const MAX_ENTRIES = 500;

    // Log level constants.
    public const DEBUG = 'debug';
    public const INFO  = 'info';
    public const WARN  = 'warn';
    public const ERROR = 'error';

    /** Log a debug-level message (only when WP_DEBUG is true). */
    public function debug(string $message, array $context = []): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->write(self::DEBUG, $message, $context);
        }
    }

    /** Log an informational message. */
    public function info(string $message, array $context = []): void
    {
        $this->write(self::INFO, $message, $context);
    }

    /** Log a warning. */
    public function warn(string $message, array $context = []): void
    {
        $this->write(self::WARN, $message, $context);
    }

    /** Log an error. */
    public function error(string $message, array $context = []): void
    {
        $this->write(self::ERROR, $message, $context);
    }

    /**
     * Retrieve stored log entries, newest first.
     *
     * @param int    $limit Maximum number of entries to return.
     * @param string $level Optional level filter ('debug'|'info'|'warn'|'error').
     * @return list<array{timestamp:int,level:string,message:string,context:array<mixed>}>
     */
    public function getLogs(int $limit = 100, string $level = ''): array
    {
        $entries = $this->loadEntries();

        if ($level !== '') {
            $entries = array_values(
                array_filter($entries, static fn(array $e): bool => $e['level'] === $level)
            );
        }

        // Return newest first.
        $entries = array_reverse($entries);

        return array_slice($entries, 0, max(1, $limit));
    }

    /** Remove all stored log entries. */
    public function clearLogs(): void
    {
        update_option(self::OPTION_KEY, [], false);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Write a single entry to the circular buffer.
     *
     * @param string               $level   Log level.
     * @param string               $message Human-readable message.
     * @param array<string, mixed> $context Arbitrary structured context.
     */
    private function write(string $level, string $message, array $context): void
    {
        $entries = $this->loadEntries();

        $entries[] = [
            'timestamp' => time(),
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
        ];

        // Trim to max size (keep most recent).
        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        update_option(self::OPTION_KEY, $entries, false);
    }

    /**
     * Load raw entries from the option store.
     *
     * @return list<array{timestamp:int,level:string,message:string,context:array<mixed>}>
     */
    private function loadEntries(): array
    {
        $raw = get_option(self::OPTION_KEY, []);
        return is_array($raw) ? $raw : [];
    }
}
