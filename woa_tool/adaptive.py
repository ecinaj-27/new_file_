from __future__ import annotations

import numpy as np


def a_linear(t: int, T: int, a_max: float = 2.0, a_min: float = 0.0) -> float:
    return a_max - (a_max - a_min) * (t / float(T))


def a_sin(t: int, T: int, a_max: float = 2.0, a_min: float = 0.0, mu: float = 1.0) -> float:
    return (a_max - a_min) * np.sin(mu * np.pi * t / float(T)) + a_min


def a_cos(t: int, T: int, a_max: float = 2.0, a_min: float = 0.0, mu: float = 1.0) -> float:
    return (a_max - a_min) * np.cos(mu * np.pi * t / float(T)) + a_min


def a_tan(t: int, T: int, a_max: float = 2.0, a_min: float = 0.0, mu: float = 0.4) -> float:
    return (a_max - a_min) * np.tan(mu * np.pi * t / float(T)) + a_min


def a_log(t: int, T: int, a_max: float = 2.0, a_min: float = 0.0) -> float:
    val = 0.5 * (1 + t) * max(T - t, 1)
    return (a_max - a_min) * np.log(val) + a_min


def a_square(t: int, T: int, a_max: float = 2.0, a_min: float = 0.0) -> float:
    return (a_max - a_min) * (t / float(T)) ** 2 + a_min


def modulate_by_diversity(a_value: float, diversity: float, d_min: float = 1e-8) -> float:
    # Simple sigmoid-like modulation to increase exploration when diversity low
    phi = 1.0 / (1.0 + np.exp(-(diversity - d_min) * 10))
    return float(a_value * (0.5 + 0.5 * phi))

