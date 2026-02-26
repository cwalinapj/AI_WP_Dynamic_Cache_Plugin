/**
 * Cloudflare Queue message handlers for purge and preload operations.
 * Messages are batched by the runtime, deduplication and fan-out happen here.
 */

import type { Env } from '../index';
import { purgeR2ByTag } from '../cache/r2Cache';
import { purgeFromEdgeCache } from '../cache/edgeCache';
import { getKeysForTag } from '../cache/tags';
import { planPreload } from '../preload/planner';
import { executePreloadsEagerly } from '../preload/runner';

// ---------------------------------------------------------------------------
// Message type contracts
// ---------------------------------------------------------------------------

/** Payload for a cache-purge queue message. */
export interface PurgeMessage {
  /** Cache tags to purge. */
  tags: string[];
  /** WordPress site identifier. */
  siteId: string;
  /** Unix millisecond timestamp when the purge was requested. */
  timestamp: number;
}

/** Payload for a cache-preload queue message. */
export interface PreloadMessage {
  /** Absolute URLs to warm. */
  urls: string[];
  /** Priority band: 'high' | 'normal' | 'low'. */
  priority: string;
  /** WordPress site identifier. */
  siteId: string;
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

/**
 * Processes a batch of purge messages from the purge queue.
 *
 * Tags are deduplicated across all messages in the batch so a tag that
 * appears in multiple messages is only purged once per batch.
 */
export async function handlePurgeQueue(
  batch: MessageBatch<PurgeMessage>,
  env: Env,
): Promise<void> {
  // Deduplicate tags across the whole batch
  const allTags = new Set<string>();
  for (const msg of batch.messages) {
    for (const tag of msg.body.tags) {
      allTags.add(tag);
    }
  }

  await Promise.allSettled(
    [...allTags].map(async (tag) => {
      // Resolve affected URLs from KV index before deleting R2 objects
      const keys = await getKeysForTag(env.CACHE_TAGS, tag);

      // Purge R2 objects
      await purgeR2ByTag(env.CACHE_BUCKET, env.CACHE_TAGS, tag);

      // Purge Edge cache (keys here are R2 paths, not HTTP URLs, so we only
      // purge Edge entries for tags that carry full URLs in their index —
      // in practice callers store canonical HTTP URLs as tag index values).
      const urlLikeKeys = keys.filter((k) => k.startsWith('http'));
      if (urlLikeKeys.length > 0) {
        await purgeFromEdgeCache(urlLikeKeys);
      }
    }),
  );

  // Acknowledge all messages
  batch.ackAll();
}

/**
 * Processes a batch of preload messages from the preload queue.
 */
export async function handlePreloadQueue(
  batch: MessageBatch<PreloadMessage>,
  env: Env,
): Promise<void> {
  // Merge and deduplicate URLs across the whole batch
  const urlSet = new Set<string>();
  let highestPriority: 'high' | 'normal' | 'low' = 'low';

  const priorityRank: Record<string, number> = { high: 2, normal: 1, low: 0 };

  for (const msg of batch.messages) {
    for (const url of msg.body.urls) {
      urlSet.add(url);
    }
    const p = msg.body.priority;
    if ((priorityRank[p] ?? 0) > (priorityRank[highestPriority] ?? 0)) {
      highestPriority = p as 'high' | 'normal' | 'low';
    }
  }

  const jobs = planPreload([...urlSet], highestPriority);

  // Queue handlers do not receive an ExecutionContext, so preloads are awaited
  // directly here. The Cloudflare runtime keeps the handler alive until this
  // promise settles.
  await executePreloadsEagerly(jobs, env);

  batch.ackAll();
}
