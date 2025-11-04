from __future__ import annotations

import numpy as np
from typing import Tuple


def opposite(pop: np.ndarray, bounds: Tuple[np.ndarray, np.ndarray]) -> np.ndarray:
    lower, upper = bounds
    return lower + upper - pop


def select_better(pop: np.ndarray, pop_opp: np.ndarray, fitness: np.ndarray, fitness_opp: np.ndarray) -> tuple:
    mask = fitness_opp < fitness
    new_pop = pop.copy()
    new_pop[mask] = pop_opp[mask]
    new_fit = fitness.copy()
    new_fit[mask] = fitness_opp[mask]
    return new_pop, new_fit

