/**
 * SiteLock Durable Object — provides distributed, per-site mutual exclusion.
 *
 * A single instance is created per site (keyed by site ID).  Callers acquire
 * the lock before performing operations that must not run concurrently (e.g.
 * full-site cache rebuilds) and release it when done.  A 30-second timeout
 * auto-releases the lock so a crashed caller can never leave it held forever.
 */

const LOCK_TIMEOUT_MS = 30_000;

interface LockState {
  locked: boolean;
  acquiredAt: number;
  owner: string;
}

export class SiteLock implements DurableObject {
  private state: DurableObjectState;
  private lock: LockState = { locked: false, acquiredAt: 0, owner: '' };
  private timeoutId: ReturnType<typeof setTimeout> | null = null;

  constructor(state: DurableObjectState) {
    this.state = state;
  }

  async fetch(request: Request): Promise<Response> {
    const url = new URL(request.url);

    switch (url.pathname) {
      case '/lock':
        return this.handleAcquire(request);
      case '/unlock':
        return this.handleRelease(request);
      case '/status':
        return this.handleStatus();
      default:
        return new Response('Not found', { status: 404 });
    }
  }

  // ---------------------------------------------------------------------------
  // Lock operations
  // ---------------------------------------------------------------------------

  private async handleAcquire(request: Request): Promise<Response> {
    await this.rehydrate();

    // Auto-release if previous holder timed out
    if (this.lock.locked && Date.now() - this.lock.acquiredAt > LOCK_TIMEOUT_MS) {
      this.releaseLock();
    }

    if (this.lock.locked) {
      return Response.json(
        { locked: false, reason: 'busy', owner: this.lock.owner },
        { status: 423 },
      );
    }

    const body = (await request.json().catch(() => ({}))) as Record<string, unknown>;
    const owner = typeof body['owner'] === 'string' ? body['owner'] : 'unknown';

    this.lock = { locked: true, acquiredAt: Date.now(), owner };
    await this.persist();

    // Auto-release after timeout
    this.scheduleAutoRelease();

    return Response.json({ locked: true, owner, expiresIn: LOCK_TIMEOUT_MS });
  }

  private async handleRelease(request: Request): Promise<Response> {
    await this.rehydrate();

    const body = (await request.json().catch(() => ({}))) as Record<string, unknown>;
    const owner = typeof body['owner'] === 'string' ? body['owner'] : '';

    // Only the lock owner may release it
    if (this.lock.locked && owner && this.lock.owner !== owner) {
      return Response.json({ released: false, reason: 'not_owner' }, { status: 403 });
    }

    this.releaseLock();
    await this.persist();

    return Response.json({ released: true });
  }

  private handleStatus(): Response {
    const ttlMs =
      this.lock.locked
        ? Math.max(0, LOCK_TIMEOUT_MS - (Date.now() - this.lock.acquiredAt))
        : 0;

    return Response.json({
      locked: this.lock.locked,
      owner: this.lock.locked ? this.lock.owner : null,
      ttlMs,
    });
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  private releaseLock(): void {
    this.lock = { locked: false, acquiredAt: 0, owner: '' };
    if (this.timeoutId !== null) {
      clearTimeout(this.timeoutId);
      this.timeoutId = null;
    }
  }

  private scheduleAutoRelease(): void {
    if (this.timeoutId !== null) clearTimeout(this.timeoutId);
    this.timeoutId = setTimeout(() => {
      this.releaseLock();
      this.persist().catch((err) => console.error('[SiteLock] auto-release persist failed:', err));
    }, LOCK_TIMEOUT_MS);
  }

  private async persist(): Promise<void> {
    await this.state.storage.put<LockState>('lock', this.lock);
  }

  private async rehydrate(): Promise<void> {
    const stored = await this.state.storage.get<LockState>('lock');
    if (stored) this.lock = stored;
  }
}
