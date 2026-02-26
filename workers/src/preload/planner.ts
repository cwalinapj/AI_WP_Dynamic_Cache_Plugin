/**
 * Preload priority planner — converts a list of URLs into a sorted queue of
 * preload jobs so high-value pages are warmed first.
 */

/** A single URL scheduled for cache warming. */
export interface PreloadJob {
  /** Absolute URL to preload. */
  url: string;
  /** Numeric priority — higher values are processed first. */
  priority: number;
  /** Unix millisecond timestamp when the job was created. */
  scheduledAt: number;
}

const PRIORITY_SCORES: Record<'high' | 'normal' | 'low', number> = {
  high: 100,
  normal: 50,
  low: 10,
};

/**
 * Builds a sorted list of {@link PreloadJob} objects from a set of URLs.
 *
 * The base priority is determined by `basePriority`; individual URLs may
 * receive a bonus score via {@link scoreUrl} based on their path structure.
 *
 * @param urls         Absolute URLs to schedule.
 * @param basePriority Coarse priority band for the whole batch.
 * @returns Jobs sorted descending by priority (highest first).
 */
export function planPreload(
  urls: string[],
  basePriority: 'high' | 'normal' | 'low',
): PreloadJob[] {
  const base = PRIORITY_SCORES[basePriority];
  const scheduledAt = Date.now();

  const jobs: PreloadJob[] = urls.map((url) => ({
    url,
    priority: base + scoreUrl(url),
    scheduledAt,
  }));

  // Descending — highest priority first
  jobs.sort((a, b) => b.priority - a.priority);

  return jobs;
}

/**
 * Returns a URL-specific priority bonus.
 *
 * Scoring heuristics (additive):
 * - Homepage (`/` or empty path): +30
 * - Category / tag / archive pages: +20
 * - Short paths (≤ 2 segments): +10
 * - Deep single-post paths (≥ 4 segments): no bonus
 */
export function scoreUrl(url: string): number {
  let pathname: string;
  try {
    pathname = new URL(url).pathname;
  } catch {
    return 0;
  }

  // Homepage
  if (pathname === '/' || pathname === '') return 30;

  // Category / tag / archive patterns
  if (/^\/(category|tag|author|archive)\//i.test(pathname)) return 20;

  // Shallow paths — likely important landing pages
  const segments = pathname.split('/').filter(Boolean);
  if (segments.length <= 2) return 10;

  return 0;
}
