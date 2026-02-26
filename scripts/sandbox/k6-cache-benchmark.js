import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  vus: 20,
  duration: '60s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1200'],
  },
};

const target = __ENV.TARGET_URL || 'https://example.com/';

export default function () {
  const response = http.get(target, {
    tags: { scenario: 'cache-benchmark' },
    headers: {
      'x-ai-sandbox-benchmark': '1',
    },
  });

  check(response, {
    'status 200': (r) => r.status === 200,
    'cache marker exists': (r) => !!r.headers['X-AI-Dynamic-Cache-Strategy'],
  });

  sleep(0.5);
}

export function handleSummary(data) {
  const metrics = {
    p50_latency_ms: data.metrics.http_req_duration.values['p(50)'] || 0,
    p95_latency_ms: data.metrics.http_req_duration.values['p(95)'] || 0,
    p99_latency_ms: data.metrics.http_req_duration.values['p(99)'] || 0,
    request_fail_rate: data.metrics.http_req_failed.values.rate || 0,
  };

  return {
    stdout: JSON.stringify(metrics, null, 2),
    'sandbox-results/k6-summary.json': JSON.stringify(metrics, null, 2),
  };
}
