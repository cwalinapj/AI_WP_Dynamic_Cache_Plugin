/**
 * Cache policy management — determines TTL, tags, bypass conditions and the
 * active caching strategy for a given request.
 */

import type { Env } from '../index';

/** Available caching strategies, ordered from lightest to most aggressive. */
export type CacheStrategy = 'disk_only' | 'disk_edge' | 'disk_r2' | 'full';

/** Resolved caching policy for a single request. */
export interface CachePolicy {
  /** How long (in seconds) the response may be cached. */
  ttl: number;
  /** Cache tags to associate with this response. */
  tags: string[];
  /** When true, skip all caches and go straight to origin. */
  bypass: boolean;
  /** Active caching strategy read from KV config. */
  strategy: CacheStrategy;
}

const KV_STRATEGY_KEY = 'config:strategy';

const DEFAULT_STRATEGY: CacheStrategy = 'full';

// TTL constants (seconds)
const TTL_HTML = 300;
const TTL_STATIC_ASSET = 31_536_000; // 1 year
const TTL_API = 0;

/** Paths that should always bypass the cache. */
const BYPASS_PATHS = ['/wp-admin', '/wp-login.php', '/wp-json/'];

/** Cookie name prefixes that indicate a logged-in WordPress user. */
const LOGGED_IN_COOKIE_PREFIXES = ['wordpress_logged_in_', 'wp-postpass_'];

/**
 * Returns true when the request should skip all caches.
 */
export function shouldBypass(request: Request): boolean {
  if (request.method === 'POST') return true;

  const url = new URL(request.url);
  for (const bypassPath of BYPASS_PATHS) {
    if (url.pathname.startsWith(bypassPath)) return true;
  }

  const cookieHeader = request.headers.get('Cookie') ?? '';
  for (const prefix of LOGGED_IN_COOKIE_PREFIXES) {
    if (cookieHeader.includes(prefix)) return true;
  }

  return false;
}

/**
 * Resolves the full caching policy for an incoming request.
 *
 * Reads the active {@link CacheStrategy} from KV so it can be changed
 * at runtime without redeploying the Worker.
 */
export async function getPolicyForRequest(request: Request, env: Env): Promise<CachePolicy> {
  if (shouldBypass(request)) {
    return { ttl: 0, tags: [], bypass: true, strategy: DEFAULT_STRATEGY };
  }

  // Read strategy from KV with a fallback
  const rawStrategy = await env.CACHE_TAGS.get(KV_STRATEGY_KEY);
  const strategy: CacheStrategy = isValidStrategy(rawStrategy) ? rawStrategy : DEFAULT_STRATEGY;

  const url = new URL(request.url);
  const ttl = resolveTtl(url.pathname, request.headers.get('Accept') ?? '');

  return { ttl, tags: [], bypass: false, strategy };
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function resolveTtl(pathname: string, accept: string): number {
  // API endpoints — never cache
  if (pathname.startsWith('/wp-json/') || pathname.startsWith('/api/')) {
    return TTL_API;
  }

  // Static assets — cache aggressively
  if (/\.(css|js|png|jpe?g|gif|webp|svg|woff2?|ico|ttf|eot)(\?|$)/i.test(pathname)) {
    return TTL_STATIC_ASSET;
  }

  // JSON/REST responses — treat as API
  if (accept.includes('application/json')) {
    return TTL_API;
  }

  // Everything else (HTML pages)
  return TTL_HTML;
}

function isValidStrategy(value: string | null): value is CacheStrategy {
  return (
    value === 'disk_only' ||
    value === 'disk_edge' ||
    value === 'disk_r2' ||
    value === 'full'
  );
}
