<?php
/**
 * wp-config-tuning.php
 *
 * WordPress performance tuning constants and settings.
 * Copy the relevant sections into your wp-config.php BEFORE the
 * "require_once ABSPATH . 'wp-settings.php';" line.
 *
 * @package AI_WP_Dynamic_Cache
 */

// ===========================================================================
// 1. PHP Memory Limits
// ===========================================================================
define( 'WP_MEMORY_LIMIT',     '256M' ); // Per-request memory for the front end
define( 'WP_MAX_MEMORY_LIMIT', '512M' ); // Admin / WP-CLI memory ceiling

// ===========================================================================
// 2. Debug & Query Logging
// ===========================================================================
define( 'WP_DEBUG',         false ); // Set true in development only
define( 'WP_DEBUG_LOG',     false ); // Write errors to wp-content/debug.log
define( 'WP_DEBUG_DISPLAY', false ); // Never expose errors in the browser (production)
define( 'SCRIPT_DEBUG',     false ); // Use minified scripts in production

// Uncomment to log slow database queries (threshold in seconds):
// define( 'SAVEQUERIES', true );
// add_filter( 'query', function ( $query ) {
//     global $wpdb;
//     if ( $wpdb->num_queries > 100 ) {
//         error_log( 'Query count exceeded 100: ' . $query );
//     }
//     return $query;
// } );

// ===========================================================================
// 3. Object Cache (Redis)
// ===========================================================================
define( 'WP_CACHE',          true );
define( 'WP_REDIS_HOST',     getenv( 'REDIS_HOST' ) ?: '127.0.0.1' );
define( 'WP_REDIS_PORT',     (int) ( getenv( 'REDIS_PORT' ) ?: 6379 ) );
define( 'WP_REDIS_TIMEOUT',  1 );            // Connect timeout in seconds
define( 'WP_REDIS_READ_TIMEOUT', 1 );        // Read timeout in seconds
define( 'WP_REDIS_PREFIX',   'wp_' );        // Key prefix – change per site on shared Redis
define( 'WP_REDIS_MAXTTL',   86400 );        // Maximum object TTL: 24 hours
define( 'WP_REDIS_SELECTIVE_FLUSH', true );  // Only flush keys belonging to this site

// ===========================================================================
// 4. Transient Cleanup
// ===========================================================================
// WordPress stores transients in the database when no object cache is active.
// With Redis enabled, transients are stored in cache; no DB cleanup needed.
// When running without an external object cache, schedule periodic cleanup:
//
// add_action( 'wp_scheduled_delete', function () {
//     global $wpdb;
//     $wpdb->query(
//         "DELETE FROM {$wpdb->options}
//          WHERE option_name LIKE '_transient_%'
//            AND option_name NOT LIKE '_transient_timeout_%'
//            AND option_name NOT IN (
//                SELECT CONCAT( '_transient_', SUBSTRING(option_name, 20) )
//                FROM {$wpdb->options}
//                WHERE option_name LIKE '_transient_timeout_%'
//                  AND option_value < UNIX_TIMESTAMP()
//            )"
//     );
// } );

// ===========================================================================
// 5. Heartbeat API Throttling
// ===========================================================================
// The Heartbeat API polls the server every 15–60 s, which can cause cache
// fragmentation and unnecessary PHP-FPM processes. Slow it down in the admin
// and disable it entirely on the front end.
//
// add_filter( 'heartbeat_settings', function ( array $settings ): array {
//     $settings['interval'] = 60; // Admin: poll every 60 s (default is 15–60)
//     return $settings;
// } );
//
// add_action( 'init', function () {
//     if ( ! is_admin() ) {
//         wp_deregister_script( 'heartbeat' ); // Front end: disable entirely
//     }
// } );

// ===========================================================================
// 6. WP Cron Configuration
// ===========================================================================
// Disable the built-in pseudo-cron so that scheduled events are only run by
// a real system cron job (prevents random slow page loads).
define( 'DISABLE_WP_CRON', true );

// System cron entry (run as www-data or the web server user):
// * * * * * php /var/www/html/wp-cron.php > /dev/null 2>&1
//
// Or via WP-CLI:
// * * * * * /usr/local/bin/wp cron event run --due-now --path=/var/www/html --allow-root

// ===========================================================================
// 7. Cookie Settings for Cache Compatibility
// ===========================================================================
// Using a custom cookie domain ensures that wp_logout and login cookies do
// not inadvertently vary the cache key across subdomains.
//
// define( 'COOKIE_DOMAIN',      '.example.com' );  // Leading dot = all subdomains
// define( 'COOKIEPATH',         '/' );
// define( 'SITECOOKIEPATH',     '/' );
// define( 'ADMIN_COOKIE_PATH',  '/wp-admin' );
// define( 'PLUGINS_COOKIE_PATH', '/wp-content/plugins' );

// Shorten the auth cookie lifetime to reduce the number of logged-in users
// bypassing the cache (default is 3 days for non-remembered logins):
// add_filter( 'auth_cookie_expiration', function ( int $expiration, int $user_id, bool $remember ): int {
//     return $remember ? MONTH_IN_SECONDS : 8 * HOUR_IN_SECONDS;
// }, 10, 3 );

// ===========================================================================
// 8. Concatenate / Autosave
// ===========================================================================
define( 'CONCATENATE_SCRIPTS', false ); // Let a proper asset pipeline handle this
define( 'AUTOSAVE_INTERVAL',   300 );   // 5 minutes (default is 60 s)
define( 'WP_POST_REVISIONS',   5 );     // Keep at most 5 revisions per post

// ===========================================================================
// 9. AI WP Dynamic Cache Plugin
// ===========================================================================
define( 'AI_WP_CACHE_WORKER_URL',  getenv( 'WORKER_URL' )   ?: '' );
define( 'AI_WP_CACHE_HMAC_SECRET', getenv( 'HMAC_SECRET' )  ?: '' );
