#!/usr/bin/env node
/**
 * Lighthouse CI runner for AI WP Dynamic Cache Plugin sandbox.
 * Runs Lighthouse audits against key URLs and enforces minimum thresholds.
 */

'use strict';

const fs              = require('fs');
const path            = require('path');
const lighthouse      = require('lighthouse');
const chromeLauncher  = require('chrome-launcher');

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const BASE_URL = process.env.BASE_URL || 'http://nginx';
const RESULTS_DIR = process.env.RESULTS_DIR || '/results';

/** Pages to audit */
const PAGES = [
  { name: 'homepage',    path: '/' },
  { name: 'sample_post', path: '/?p=1' },
  { name: 'category',    path: '/?cat=1' },
];

/** Minimum acceptable scores / timings */
const THRESHOLDS = {
  performance: 80,   // 0–100 score
  fcp:         2000, // First Contentful Paint  (ms)
  lcp:         3000, // Largest Contentful Paint (ms)
  tbt:         300,  // Total Blocking Time      (ms)
  cls:         0.1,  // Cumulative Layout Shift  (unitless)
};

const LIGHTHOUSE_FLAGS = {
  logLevel:   'error',
  output:     'json',
  onlyCategories: ['performance'],
  port:       null, // filled in after Chrome launch
  // Run in headless mode inside a container
  chromeFlags: ['--headless', '--no-sandbox', '--disable-dev-shm-usage'],
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function extractMetrics(lhrJson) {
  const audits = lhrJson.audits;
  return {
    performance: Math.round((lhrJson.categories.performance.score || 0) * 100),
    fcp: audits['first-contentful-paint']?.numericValue    ?? Infinity,
    lcp: audits['largest-contentful-paint']?.numericValue  ?? Infinity,
    tbt: audits['total-blocking-time']?.numericValue       ?? Infinity,
    cls: audits['cumulative-layout-shift']?.numericValue   ?? Infinity,
  };
}

function checkThresholds(name, metrics) {
  const failures = [];

  if (metrics.performance < THRESHOLDS.performance) {
    failures.push(`performance ${metrics.performance} < ${THRESHOLDS.performance}`);
  }
  if (metrics.fcp > THRESHOLDS.fcp) {
    failures.push(`FCP ${metrics.fcp.toFixed(0)}ms > ${THRESHOLDS.fcp}ms`);
  }
  if (metrics.lcp > THRESHOLDS.lcp) {
    failures.push(`LCP ${metrics.lcp.toFixed(0)}ms > ${THRESHOLDS.lcp}ms`);
  }
  if (metrics.tbt > THRESHOLDS.tbt) {
    failures.push(`TBT ${metrics.tbt.toFixed(0)}ms > ${THRESHOLDS.tbt}ms`);
  }
  if (metrics.cls > THRESHOLDS.cls) {
    failures.push(`CLS ${metrics.cls.toFixed(3)} > ${THRESHOLDS.cls}`);
  }

  if (failures.length > 0) {
    console.error(`  ✗ [${name}] threshold failures:\n    - ${failures.join('\n    - ')}`);
    return false;
  }

  console.log(`  ✓ [${name}] all thresholds passed`);
  return true;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
async function main() {
  if (!fs.existsSync(RESULTS_DIR)) {
    fs.mkdirSync(RESULTS_DIR, { recursive: true });
  }

  const timestamp   = new Date().toISOString().replace(/[:.]/g, '-');
  const outputFile  = path.join(RESULTS_DIR, `lighthouse-${timestamp}.json`);
  const allResults  = [];
  let   anyFailure  = false;

  const chrome = await chromeLauncher.launch({
    chromeFlags: ['--headless', '--no-sandbox', '--disable-dev-shm-usage'],
  });

  LIGHTHOUSE_FLAGS.port = chrome.port;

  try {
    for (const page of PAGES) {
      const url = `${BASE_URL}${page.path}`;
      console.log(`\nAuditing: ${url}`);

      const runnerResult = await lighthouse(url, LIGHTHOUSE_FLAGS);
      const lhr          = runnerResult.lhr;
      const metrics      = extractMetrics(lhr);

      console.log(`  Performance : ${metrics.performance}`);
      console.log(`  FCP         : ${metrics.fcp.toFixed(0)} ms`);
      console.log(`  LCP         : ${metrics.lcp.toFixed(0)} ms`);
      console.log(`  TBT         : ${metrics.tbt.toFixed(0)} ms`);
      console.log(`  CLS         : ${metrics.cls.toFixed(3)}`);

      const passed = checkThresholds(page.name, metrics);
      if (!passed) anyFailure = true;

      allResults.push({
        page:      page.name,
        url,
        timestamp: new Date().toISOString(),
        metrics,
        passed,
      });
    }
  } finally {
    await chrome.kill();
  }

  fs.writeFileSync(outputFile, JSON.stringify(allResults, null, 2));
  console.log(`\nResults written to ${outputFile}`);

  if (anyFailure) {
    console.error('\n❌  One or more Lighthouse thresholds failed.');
    process.exit(1);
  }

  console.log('\n✅  All Lighthouse thresholds passed.');
}

main().catch((err) => {
  console.error('Lighthouse runner error:', err);
  process.exit(1);
});
