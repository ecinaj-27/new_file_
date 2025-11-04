from __future__ import annotations

import numpy as np
from typing import Tuple


def ensure_bounds(position: np.ndarray, lower: np.ndarray, upper: np.ndarray) -> np.ndarray:
    return np.minimum(np.maximum(position, lower), upper)


def initialize_population(pop_size: int, dim: int, bounds: Tuple[np.ndarray, np.ndarray]) -> np.ndarray:
    lower, upper = bounds
    return lower + (upper - lower) * np.random.rand(pop_size, dim)


def population_diversity(pop: np.ndarray) -> float:
    # Average standard deviation across dimensions normalized by range
    if pop.shape[0] <= 1:
        return 0.0
    std = np.std(pop, axis=0)
    return float(np.mean(std))

