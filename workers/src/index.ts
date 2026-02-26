import { handleRequest } from './routes';
import { handlePurgeQueue, handlePreloadQueue } from './jobs/queueHandlers';
import type { PurgeMessage, PreloadMessage } from './jobs/queueHandlers';
import { SiteLock } from './durable/siteLock';

/** All Cloudflare bindings available to this Worker. */
export interface Env {
  /** KV namespace for cache tag indexes and nonce tracking. */
  CACHE_TAGS: KVNamespace;
  /** R2 bucket for persistent cache storage. */
  CACHE_BUCKET: R2Bucket;
  /** D1 database for analytics, experiments, and heartbeats. */
  DB: D1Database;
  /** Durable Object namespace for per-site locking. */
  SITE_LOCK: DurableObjectNamespace;
  /** Queue producer for cache purge jobs. */
  PURGE_QUEUE: Queue<PurgeMessage>;
  /** Queue producer for cache preload jobs. */
  PRELOAD_QUEUE: Queue<PreloadMessage>;
  /** Deployment environment identifier. */
  ENVIRONMENT: string;
  /** Shared secret for HMAC-SHA256 request signing (set via Wrangler secrets). */
  SIGNING_SECRET: string;
}

export { SiteLock };

export default {
  /**
   * Main fetch handler — routes all HTTP requests to the appropriate handler.
   */
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    if (request.method === 'OPTIONS') {
      return new Response(null, {
        status: 204,
        headers: {
          'Access-Control-Allow-Origin': '*',
          'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
          'Access-Control-Allow-Headers':
            'Content-Type, X-Timestamp, X-Nonce, X-Signature, X-Idempotency-Key',
          'Access-Control-Max-Age': '86400',
        },
      });
    }

    try {
      return await handleRequest(request, env, ctx);
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Internal server error';
      return Response.json({ error: message }, { status: 500 });
    }
  },

  /**
   * Queue consumer handler — processes messages from purge and preload queues.
   */
  async queue(
    batch: MessageBatch<PurgeMessage | PreloadMessage>,
    env: Env,
  ): Promise<void> {
    if (batch.queue.includes('purge')) {
      await handlePurgeQueue(batch as MessageBatch<PurgeMessage>, env);
    } else if (batch.queue.includes('preload')) {
      await handlePreloadQueue(batch as MessageBatch<PreloadMessage>, env);
    }
  },
};
