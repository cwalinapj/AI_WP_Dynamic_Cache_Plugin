import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

// ---------------------------------------------------------------------------
// Custom metrics
// ---------------------------------------------------------------------------
const cacheHits   = new Counter('cache_hits');
const cacheMisses = new Counter('cache_misses');
const cacheHitRate = new Rate('cache_hit_rate');
const pageLoadTime = new Trend('page_load_time', true);

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------
export const options = {
  scenarios: {
    warm_cache: {
      executor: 'constant-vus',
      vus: 10,
      duration: '60s',
      tags: { scenario: 'warm_cache' },
    },
    cold_start: {
      executor: 'per-vu-iterations',
      vus: 50,
      iterations: 5,
      maxDuration: '2m',
      startTime: '65s',
      tags: { scenario: 'cold_start' },
    },
    high_concurrency: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 50 },
        { duration: '60s', target: 200 },
        { duration: '30s', target: 100 },
        { duration: '20s', target: 0 },
      ],
      startTime: '3m',
      tags: { scenario: 'high_concurrency' },
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<500'],
    http_req_failed:   ['rate<0.01'],
    cache_hit_rate:    ['rate>0.70'],
  },
};

// ---------------------------------------------------------------------------
// Test URLs
// ---------------------------------------------------------------------------
const BASE_URL = __ENV.BASE_URL || 'http://nginx';

const URLS = [
  { name: 'homepage',     url: `${BASE_URL}/` },
  { name: 'sample_post',  url: `${BASE_URL}/?p=1` },
  { name: 'category',     url: `${BASE_URL}/?cat=1` },
  { name: 'sitemap',      url: `${BASE_URL}/sitemap.xml` },
];

// ---------------------------------------------------------------------------
// Default function
// ---------------------------------------------------------------------------
export default function () {
  const target = URLS[Math.floor(Math.random() * URLS.length)];

  const res = http.get(target.url, {
    headers: { 'Accept': 'text/html,application/xhtml+xml' },
    tags:    { name: target.name },
  });

  const cacheStatus = res.headers['X-Cache-Status'] || '';

  check(res, {
    'status is 200':                   (r) => r.status === 200,
    'response time < 1000ms':          (r) => r.timings.duration < 1000,
    'X-Cache-Status header present':   () => cacheStatus !== '',
  });

  pageLoadTime.add(res.timings.duration, { page: target.name });

  if (cacheStatus === 'HIT') {
    cacheHits.add(1);
    cacheHitRate.add(true);
  } else {
    cacheMisses.add(1);
    cacheHitRate.add(false);
  }

  sleep(Math.random() * 2 + 0.5); // 0.5–2.5 s think time
}

// ---------------------------------------------------------------------------
// Lifecycle hooks
// ---------------------------------------------------------------------------
export function handleSummary(data) {
  const hits   = data.metrics.cache_hits   ? data.metrics.cache_hits.values.count   : 0;
  const misses = data.metrics.cache_misses ? data.metrics.cache_misses.values.count : 0;
  const total  = hits + misses;
  const rate   = total > 0 ? ((hits / total) * 100).toFixed(1) : '0.0';

  console.log('\n========== Cache Summary ==========');
  console.log(`  Total requests : ${total}`);
  console.log(`  Cache HITs     : ${hits}`);
  console.log(`  Cache MISSes   : ${misses}`);
  console.log(`  Hit rate       : ${rate}%`);
  console.log('===================================\n');

  return {
    stdout: JSON.stringify(data, null, 2),
  };
}
