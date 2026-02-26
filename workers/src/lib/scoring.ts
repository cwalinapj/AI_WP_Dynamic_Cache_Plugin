import type { StrategyCandidateInput } from './types';

export interface EvaluatedCandidate {
  candidate: StrategyCandidateInput;
  hard_gate_passed: boolean;
  hard_gate_failures: string[];
  score: number;
  components: {
    latency: number;
    origin_load: number;
    cache_hit_quality: number;
    purge_mttr: number;
  };
}

export interface ScoringResult {
  recommended: EvaluatedCandidate | null;
  evaluated: EvaluatedCandidate[];
}

export function evaluateCandidates(candidates: StrategyCandidateInput[]): ScoringResult {
  const evaluated = candidates.map(evaluateCandidate);
  const passing = evaluated
    .filter((row) => row.hard_gate_passed)
    .sort((left, right) => right.score - left.score);

  return {
    recommended: passing[0] ?? null,
    evaluated,
  };
}

export function evaluateCandidate(candidate: StrategyCandidateInput): EvaluatedCandidate {
  const hardGateFailures: string[] = [];

  if (candidate.gates.digest_mismatch) {
    hardGateFailures.push('digest_mismatch');
  }
  if (candidate.gates.personalized_cache_leak) {
    hardGateFailures.push('personalized_cache_leak');
  }
  if (!candidate.gates.purge_within_window) {
    hardGateFailures.push('purge_exceeded_window');
  }
  if (candidate.gates.cache_key_collision) {
    hardGateFailures.push('cache_key_collision');
  }

  const latency = latencyScore(
    candidate.metrics.p50_latency_ms,
    candidate.metrics.p95_latency_ms,
    candidate.metrics.p99_latency_ms,
  );
  const originLoad = originLoadScore(candidate.metrics.origin_cpu_pct, candidate.metrics.origin_query_count);
  const cacheHitQuality = cacheHitQualityScore(
    candidate.metrics.edge_hit_ratio,
    candidate.metrics.r2_hit_ratio,
  );
  const purgeMttr = lowerIsBetterScore(candidate.metrics.purge_mttr_ms, 50, 5000);

  // Weighted model:
  // - 60% latency (p95 heavy)
  // - 20% origin load
  // - 10% cache hit quality
  // - 10% purge MTTR
  const score =
    0.6 * latency +
    0.2 * originLoad +
    0.1 * cacheHitQuality +
    0.1 * purgeMttr;

  return {
    candidate,
    hard_gate_passed: hardGateFailures.length === 0,
    hard_gate_failures: hardGateFailures,
    score,
    components: {
      latency,
      origin_load: originLoad,
      cache_hit_quality: cacheHitQuality,
      purge_mttr: purgeMttr,
    },
  };
}

function latencyScore(p50: number, p95: number, p99: number): number {
  const p50Score = lowerIsBetterScore(p50, 40, 1200);
  const p95Score = lowerIsBetterScore(p95, 80, 2500);
  const p99Score = lowerIsBetterScore(p99, 120, 4000);
  return 0.2 * p50Score + 0.6 * p95Score + 0.2 * p99Score;
}

function originLoadScore(cpuPct: number, queryCount: number): number {
  const cpuScore = lowerIsBetterScore(cpuPct, 10, 95);
  const queryScore = lowerIsBetterScore(queryCount, 10, 250);
  return 0.5 * cpuScore + 0.5 * queryScore;
}

function cacheHitQualityScore(edgeHitRatio: number, r2HitRatio?: number): number {
  const edgeScore = clamp01(edgeHitRatio);
  const r2Score = typeof r2HitRatio === 'number' ? clamp01(r2HitRatio) : edgeScore;
  return 0.7 * edgeScore + 0.3 * r2Score;
}

function lowerIsBetterScore(value: number, ideal: number, worst: number): number {
  if (!Number.isFinite(value)) {
    return 0;
  }
  if (value <= ideal) {
    return 1;
  }
  if (value >= worst) {
    return 0;
  }
  const normalized = 1 - (value - ideal) / (worst - ideal);
  return clamp01(normalized);
}

function clamp01(value: number): number {
  if (!Number.isFinite(value)) {
    return 0;
  }
  if (value < 0) {
    return 0;
  }
  if (value > 1) {
    return 1;
  }
  return value;
}
