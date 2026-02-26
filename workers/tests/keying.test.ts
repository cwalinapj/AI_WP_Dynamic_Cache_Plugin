import { describe, it, expect } from 'vitest';
import { buildCacheKey, buildR2Key } from '../src/cache/key';

describe('buildCacheKey', () => {
  it('normalises scheme to lowercase', () => {
    // URL constructor always lowercases the scheme, but we verify the contract
    const req = new Request('https://Example.COM/page');
    const key = buildCacheKey(req);
    expect(key.startsWith('https://example.com')).toBe(true);
  });

  it('sorts query parameters alphabetically', () => {
    const req = new Request('https://example.com/page?z=1&a=2&m=3');
    const key = buildCacheKey(req);
    const params = new URL(key).searchParams;
    const names = [...params.keys()];
    expect(names).toEqual([...names].sort());
  });

  it('strips UTM and click-tracking parameters', () => {
    const req = new Request(
      'https://example.com/page?utm_source=fb&utm_medium=cpc&utm_campaign=test' +
        '&utm_content=ad&utm_term=kw&fbclid=abc&gclid=xyz&keep=1',
    );
    const key = buildCacheKey(req);
    const params = new URL(key).searchParams;

    expect(params.has('utm_source')).toBe(false);
    expect(params.has('utm_medium')).toBe(false);
    expect(params.has('utm_campaign')).toBe(false);
    expect(params.has('utm_content')).toBe(false);
    expect(params.has('utm_term')).toBe(false);
    expect(params.has('fbclid')).toBe(false);
    expect(params.has('gclid')).toBe(false);
    // Non-blocked params must survive
    expect(params.get('keep')).toBe('1');
  });

  it('preserves the path unchanged', () => {
    const req = new Request('https://example.com/category/news/article-slug');
    const key = buildCacheKey(req);
    expect(new URL(key).pathname).toBe('/category/news/article-slug');
  });

  it('produces the same key for identical URLs', () => {
    const req1 = new Request('https://example.com/page?b=2&a=1');
    const req2 = new Request('https://example.com/page?a=1&b=2');
    expect(buildCacheKey(req1)).toBe(buildCacheKey(req2));
  });
});

describe('buildR2Key', () => {
  it('produces the ab/cd/... directory-style format', async () => {
    const key = await buildR2Key('https://example.com/');
    // Must match: 2 chars / 2 chars / remaining hex + .cache
    expect(key).toMatch(/^[0-9a-f]{2}\/[0-9a-f]{2}\/[0-9a-f]+\.cache$/);
  });

  it('uses the first two hex chars as the top-level directory', async () => {
    const key = await buildR2Key('https://example.com/page');
    const parts = key.split('/');
    expect(parts[0]).toHaveLength(2);
    expect(parts[1]).toHaveLength(2);
  });

  it('produces a different key for different cache keys', async () => {
    const k1 = await buildR2Key('https://example.com/page-a');
    const k2 = await buildR2Key('https://example.com/page-b');
    expect(k1).not.toBe(k2);
  });

  it('is deterministic — same input yields same key', async () => {
    const input = 'https://example.com/stable';
    const k1 = await buildR2Key(input);
    const k2 = await buildR2Key(input);
    expect(k1).toBe(k2);
  });
});
