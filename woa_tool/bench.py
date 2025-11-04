from __future__ import annotations

import argparse
import json
import time
from typing import Callable, Dict, List, Tuple

import numpy as np

from .algorithms import run_woa, run_ewoa
from .metrics import (
    RunHistory,
    summarize_runtime_seconds,
    summarize_average_eer,
    compute_normalized_convergence_rate,
)


# === Objective functions ===
def rosenbrock(x: np.ndarray) -> float:
    x = np.asarray(x, dtype=float)
    return float(np.sum(100.0 * (x[1:] - x[:-1] ** 2.0) ** 2.0 + (1 - x[:-1]) ** 2.0))


def griewank(x: np.ndarray) -> float:
    x = np.asarray(x, dtype=float)
    dim = x.size
    sum_term = np.sum((x ** 2) / 4000.0)
    prod_term = np.prod(np.cos(x / np.sqrt(np.arange(1, dim + 1, dtype=float))))
    return float(sum_term - prod_term + 1.0)


FUNCTIONS: Dict[str, Tuple[Callable[[np.ndarray], float], Tuple[float, float]]] = {
    # name: (objective, (lower_bound, upper_bound))
    "rosenbrock": (rosenbrock, (-5.0, 10.0)),
    "griewank": (griewank, (-600.0, 600.0)),
}


def run_many(
    func: Callable[[np.ndarray], float],
    bounds: Tuple[float, float],
    dim: int,
    pop: int,
    iters: int,
    runs: int,
    algo: str,
    seed: int | None,
):
    low, high = bounds
    lb = np.full((dim,), low, dtype=float)
    ub = np.full((dim,), high, dtype=float)

    woa_vals: List[float] = []
    ewoa_vals: List[float] = []
    woa_histories: List[RunHistory] = []
    ewoa_histories: List[RunHistory] = []

    rng = np.random.default_rng(seed)

    for r in range(runs):
        run_seed = int(rng.integers(1, 10_000_000)) if seed is not None else None

        if algo in ("woa", "both"):
            _, best_fit, hist = run_woa(func, dim, (lb, ub), pop_size=pop, iters=iters, seed=run_seed)
            woa_vals.append(float(best_fit))
            woa_histories.append(hist)

        if algo in ("ewoa", "both"):
            _, best_fit, hist = run_ewoa(func, dim, (lb, ub), pop_size=pop, iters=iters, seed=run_seed)
            ewoa_vals.append(float(best_fit))
            ewoa_histories.append(hist)

    def summarize(vals: List[float], histories: List[RunHistory]):
        # block summaries by contiguous thirds (or by 10s if runs>=10)
        n = len(vals)
        blocks = []
        if n > 0:
            if n >= 10:
                step = max(1, n // 3)
                starts = [0, step, min(2 * step, n - 1)]
                ends = [min(step - 1, n - 1), min(2 * step - 1, n - 1), n - 1]
            else:
                # single block
                starts = [0]
                ends = [n - 1]
            for s, e in zip(starts, ends):
                seg = vals[s : e + 1]
                blocks.append({
                    "runs": [int(s + 1), int(e + 1)],
                    "best_mean": float(np.mean(seg)),
                    "best_std": float(np.std(seg)) if len(seg) > 1 else 0.0,
                })

        conv_rates = [compute_normalized_convergence_rate(h) for h in histories]

        return {
            "best_mean": float(np.mean(vals)) if vals else None,
            "best_std": float(np.std(vals)) if len(vals) > 1 else 0.0,
            "average_eer": summarize_average_eer(histories),
            "runtime_s": summarize_runtime_seconds(histories),
            "convergence_rate_mean": float(np.mean(conv_rates)) if conv_rates else 0.0,
            "convergence_rate_std": float(np.std(conv_rates)) if len(conv_rates) > 1 else 0.0,
            "all": vals,
            "run_block_summaries": blocks,
        }

    out = {}
    if algo in ("woa", "both"):
        out["woa"] = summarize(woa_vals, woa_histories)
    if algo in ("ewoa", "both"):
        out["ewoa"] = summarize(ewoa_vals, ewoa_histories)
    return out


def main():
    p = argparse.ArgumentParser(description="WOA/EWOA benchmark runner")
    p.add_argument("--functions", nargs="+", default=["rosenbrock", "griewank"], help="Functions to run")
    p.add_argument("--algo", choices=["woa", "ewoa", "both"], default="both")
    p.add_argument("--pop", type=int, default=30)
    p.add_argument("--iters", type=int, default=100)
    p.add_argument("--runs", type=int, default=30)
    p.add_argument("--seed", type=int, default=None)
    p.add_argument("--dim", type=int, default=30)

    args = p.parse_args()

    results = {}
    for name in args.functions:
        key = name.lower()
        if key not in FUNCTIONS:
            continue
        func, (lo, hi) = FUNCTIONS[key]
        start = time.time()
        res = run_many(func, (lo, hi), args.dim, args.pop, args.iters, args.runs, args.algo, args.seed)
        res["elapsed_s"] = float(time.time() - start)
        results[key] = res

    print(json.dumps(results, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())



