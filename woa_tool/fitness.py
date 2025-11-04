from __future__ import annotations

import numpy as np
from typing import Callable


def evaluate_population(pop: np.ndarray, objective: Callable[[np.ndarray], float]) -> np.ndarray:
    return np.array([objective(ind) for ind in pop], dtype=float)

