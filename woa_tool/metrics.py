from __future__ import annotations

import numpy as np
from dataclasses import dataclass, field
from typing import List, Dict


@dataclass
class RunHistory:
    best_fitness_per_iter: list = field(default_factory=list)
    exploration_steps: int = 0
    exploitation_steps: int = 0
    times_ms_per_iter: list = field(default_factory=list)
    exploration_count_per_iter: list = field(default_factory=list)
    exploitation_count_per_iter: list = field(default_factory=list)
    # Average population diversity per iteration (if provided by the algorithm loop)
    diversity_per_iter: list = field(default_factory=list)

    @property
    def exploration_ratio(self) -> float:
        total = self.exploration_steps + self.exploitation_steps
        if total == 0:
            return 0.0
        return self.exploration_steps / float(total)

    def eer_curve(self) -> List[float]:
        curve = []
        for e, x in zip(self.exploration_count_per_iter, self.exploitation_count_per_iter):
            denom = e + x
            curve.append(0.0 if denom == 0 else e / float(denom))
        return curve


def summarize_eer_over_runs(histories: List[RunHistory], interval: int = 5) -> Dict[str, List[float]]:
    # Average EER across runs, optionally summarized by intervals of iterations
    if not histories:
        return {"eer_mean": [], "eer_interval_means": []}
    max_len = max(len(h.best_fitness_per_iter) for h in histories)
    eer_matrix = np.full((len(histories), max_len), np.nan)
    for i, h in enumerate(histories):
        c = np.array(h.eer_curve(), dtype=float)
        eer_matrix[i, :len(c)] = c
    eer_mean = np.nanmean(eer_matrix, axis=0).tolist()
    # Interval summaries
    interval_means = []
    for start in range(0, max_len, interval):
        interval_means.append(float(np.nanmean(eer_matrix[:, start:start+interval])))
    return {"eer_mean": eer_mean, "eer_interval_means": interval_means}


def summarize_runtime_seconds(histories: List[RunHistory]) -> float:
    # Average total runtime (in seconds) across runs
    if not histories:
        return 0.0
    totals = [float(np.sum(h.times_ms_per_iter)) / 1000.0 for h in histories]
    return float(np.mean(totals))


def summarize_average_eer(histories: List[RunHistory]) -> float:
    # Mean of EER values across trials (averaging the per-iteration means first)
    if not histories:
        return 0.0
    eer_means_per_run = []
    for h in histories:
        c = np.array(h.eer_curve(), dtype=float)
        if c.size == 0:
            continue
        eer_means_per_run.append(float(np.nanmean(c)))
    return float(np.mean(eer_means_per_run)) if eer_means_per_run else 0.0


def summarize_average_diversity(histories: List[RunHistory]) -> float:
    # Average population diversity across runs and iterations
    if not histories:
        return 0.0
    vals = []
    for h in histories:
        if h.diversity_per_iter:
            vals.append(float(np.mean(np.array(h.diversity_per_iter, dtype=float))))
    return float(np.mean(vals)) if vals else 0.0


def convergence_stats_from_history(history: RunHistory) -> Dict[str, float]:
    # Derive: iterations to converge, time to converge (s), and final best fitness
    y = np.array(history.best_fitness_per_iter, dtype=float)
    if y.size == 0:
        return {"iterations_to_converge": 0.0, "convergence_time_s": 0.0, "best_fitness_value": float('inf')}
    final_best = float(np.min(y))
    # First index where we reach the final best (within tolerance)
    tol = 1e-12
    hits = np.where(np.isclose(y, final_best, rtol=0.0, atol=tol))[0]
    idx = int(hits[0]) if hits.size > 0 else (len(y) - 1)
    iters_to_conv = float(idx + 1)
    # Time to converge: sum of iteration times up to and including idx
    tms = np.array(history.times_ms_per_iter, dtype=float)
    if tms.size > 0:
        t_to_conv = float(np.sum(tms[: idx + 1]) / 1000.0)
    else:
        t_to_conv = 0.0
    return {
        "iterations_to_converge": iters_to_conv,
        "convergence_time_s": t_to_conv,
        "best_fitness_value": final_best,
    }


def normalize_fitness_values(fitness_values: List[float]) -> List[float]:
    """Normalize fitness values to 0-1 range for fair comparison across functions."""
    if not fitness_values or len(fitness_values) == 0:
        return []
    
    values = np.array(fitness_values, dtype=float)
    min_val = np.min(values)
    max_val = np.max(values)
    
    if max_val == min_val:
        return [0.5] * len(values)  # All values are the same
    
    normalized = (values - min_val) / (max_val - min_val)
    return normalized.tolist()


def compute_normalized_convergence_rate(history: RunHistory) -> float:
    """Compute normalized convergence rate (0-1) based on relative improvement."""
    y = np.array(history.best_fitness_per_iter, dtype=float)
    if y.size < 2:
        return 0.0
    
    # Calculate relative improvement per iteration
    improvements = []
    for i in range(1, len(y)):
        prev = y[i-1]
        curr = y[i]
        if prev != 0:
            rate = abs(prev - curr) / abs(prev)
            improvements.append(rate)
    
    if not improvements:
        return 0.0
    
    # Return average improvement rate (0-1)
    return float(np.mean(improvements))


def compute_robust_standard_deviation(fitness_values: List[float]) -> float:
    """Compute standard deviation with proper handling of edge cases."""
    if not fitness_values or len(fitness_values) < 2:
        return 0.0
    
    values = np.array(fitness_values, dtype=float)
    if len(values) < 2:
        return 0.0
    
    return float(np.std(values))


def compute_dynamic_eer_ratio(history: RunHistory) -> float:
    """Compute dynamic EER ratio with proper exploration/exploitation tracking."""
    if not history.exploration_count_per_iter or not history.exploitation_count_per_iter:
        return 0.0
    
    exploration_counts = np.array(history.exploration_count_per_iter, dtype=float)
    exploitation_counts = np.array(history.exploitation_count_per_iter, dtype=float)
    
    total_exploration = np.sum(exploration_counts)
    total_exploitation = np.sum(exploitation_counts)
    
    if total_exploration + total_exploitation == 0:
        return 0.0
    
    return float(total_exploration / (total_exploration + total_exploitation))


def compute_accurate_execution_time(histories: List[RunHistory]) -> float:
    """Compute accurate execution time excluding setup/plotting overhead."""
    if not histories:
        return 0.0
    
    # Use only the actual algorithm execution time
    times = []
    for h in histories:
        if h.times_ms_per_iter:
            total_ms = np.sum(h.times_ms_per_iter)
            times.append(total_ms / 1000.0)  # Convert to seconds
    
    return float(np.mean(times)) if times else 0.0