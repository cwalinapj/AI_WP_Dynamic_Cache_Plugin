#!/usr/bin/env node

import { readFile } from 'node:fs/promises';
import { randomUUID, createHash, createHmac } from 'node:crypto';

const workerBaseUrl = process.env.WORKER_BASE_URL || '';
const pluginId = process.env.PLUGIN_ID || process.env.SITE_ID || 'wp-plugin';
const pluginSecret = process.env.WP_PLUGIN_SHARED_SECRET || '';
const capabilityToken = process.env.CAP_TOKEN_SANDBOX_WRITE || '';
const siteId = process.env.SITE_ID || '';
const workerId = process.env.WORKER_ID || 'worker-1';
const strategy = process.env.STRATEGY || 'edge-balanced';
const inputFile = process.env.LOADTEST_FILE || 'sandbox-results/page-tests.json';
const endpointPath = '/plugin/wp/sandbox/loadtests/report';

if (!workerBaseUrl || !pluginSecret || !capabilityToken || !siteId) {
  console.error('Missing required env vars: WORKER_BASE_URL, WP_PLUGIN_SHARED_SECRET, CAP_TOKEN_SANDBOX_WRITE, SITE_ID');
  process.exit(1);
}

const raw = await readFile(inputFile, 'utf8');
const pageTests = JSON.parse(raw);
if (!Array.isArray(pageTests) || pageTests.length === 0) {
  console.error(`Input file ${inputFile} must contain a non-empty JSON array.`);
  process.exit(1);
}

const payload = {
  site_id: siteId,
  worker_id: workerId,
  strategy,
  page_tests: pageTests,
};

const body = JSON.stringify(payload);
const timestamp = Math.floor(Date.now() / 1000).toString();
const nonce = randomUUID();
const bodyHash = createHash('sha256').update(body).digest('hex');
const canonical = `${timestamp}.${nonce}.POST.${endpointPath}.${bodyHash}`;
const signature = createHmac('sha256', pluginSecret).update(canonical).digest('hex');

const endpoint = `${workerBaseUrl.replace(/\/$/, '')}${endpointPath}`;
const response = await fetch(endpoint, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Plugin-Id': pluginId,
    'X-Plugin-Timestamp': timestamp,
    'X-Plugin-Nonce': nonce,
    'X-Plugin-Signature': signature,
    'X-Capability-Token': capabilityToken,
    'Idempotency-Key': randomUUID(),
  },
  body,
});

const text = await response.text();
console.log(`HTTP ${response.status}`);
console.log(text);

if (!response.ok) {
  process.exit(1);
}
