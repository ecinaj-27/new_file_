"""
Image-only feature extraction for mammograms.

Features returned (all computed from TIFF image):
- glcm_*       : Haralick texture metrics (ASM, contrast, entropy, etc.)
- hist_*       : intensity distribution stats
- edge_*       : edge magnitude/orientation cues
- sharp_*      : focus/texture sharpness
- blob_*       : micro-calcification proxies via LoG blobs
- asym_*       : bilateral asymmetry (left vs right)
- shape_*      : coarse ROI shape from Otsu threshold (largest region)
- spic_*       : spiculation proxies around ROI (edge ring density, orientation dispersion)

No metadata is read here; this is purely image-derived.
"""

from __future__ import annotations
from typing import Dict, Tuple

import numpy as np
import mahotas
from skimage import io, color, exposure, morphology, measure, util
from skimage.filters import sobel, laplace, threshold_otsu
from skimage.feature import canny, blob_log, structure_tensor
from skimage.transform import resize
from scipy.stats import skew, kurtosis


# -------------------------------------------------------------------------
# Utility helpers
# -------------------------------------------------------------------------

def _safe_load_grayscale(path: str, downscale_max: int = 1024) -> np.ndarray:
    """
    Load image, drop alpha if RGBA, convert to grayscale float32 in [0,1].
    Optionally downscale largest side to `downscale_max` to speed up ops.
    """
    img = io.imread(path)

    # Drop alpha if present (RGBA -> RGB)
    if img.ndim == 3 and img.shape[-1] == 4:
        img = img[..., :3]

    # Convert to grayscale if RGB
    if img.ndim == 3:
        img = color.rgb2gray(img)

    img = util.img_as_float32(img)

    # Downscale if very large (keeps aspect ratio)
    h, w = img.shape[:2]
    m = max(h, w)
    if downscale_max is not None and m > downscale_max:
        scale = downscale_max / float(m)
        new_h, new_w = int(h * scale), int(w * scale)
        img = resize(img, (new_h, new_w),
                     order=1, anti_aliasing=True, preserve_range=True).astype(np.float32)
    return img


def _nan_safe(val: float, default: float = 0.0) -> float:
    return float(val) if np.isfinite(val) else float(default)


def _quantiles(a: np.ndarray, qs=(25, 50, 75)) -> Tuple[float, ...]:
    try:
        return tuple(float(np.percentile(a, q)) for q in qs)
    except Exception:
        return tuple(0.0 for _ in qs)


# -------------------------------------------------------------------------
# Feature groups
# -------------------------------------------------------------------------

def _glcm_features(img: np.ndarray) -> dict[str, float]:
    """
    IMPORTANT: ignore_zeros=True to avoid background zeros (from ROI masking)
    dominating co-occurrence counts.
    """
    im8 = (img * 255).astype(np.uint8)
    feats = mahotas.features.haralick(
        im8, distance=1, ignore_zeros=True  # ← key change
    ).mean(axis=0)
    names = [
        "ASM", "contrast", "correlation", "variance",
        "IDM", "sum_avg", "sum_var", "sum_entropy",
        "entropy", "diff_var", "diff_entropy",
        "IMC1", "IMC2"
    ]
    return {f"glcm_{n}": float(v) for n, v in zip(names, feats)}


def _histogram_features(img: np.ndarray) -> Dict[str, float]:
    vals = img.ravel().astype(np.float32)
    vals = vals[np.isfinite(vals)]
    mean = _nan_safe(vals.mean())
    std  = _nan_safe(vals.std())
    sk   = _nan_safe(skew(vals))
    ku   = _nan_safe(kurtosis(vals))
    q25, q50, q75 = _quantiles(vals, (25, 50, 75))
    return {
        "hist_mean": mean,
        "hist_std": std,
        "hist_skew": sk,
        "hist_kurtosis": ku,
        "hist_q25": q25,
        "hist_q50": q50,
        "hist_q75": q75,
        # keep for backwards-compat; historically same as mean in this codebase
        "density_index": mean,
    }


def _edge_gradient_features(img: np.ndarray) -> Dict[str, float]:
    sob = sobel(img)
    sob_mean = _nan_safe(sob.mean())
    sob_std  = _nan_safe(sob.std())

    sigma = max(0.8, 0.002 * max(img.shape))
    can = canny(img, sigma=sigma)
    edge_ratio = float(can.mean())

    # Manual eigenvalue computation (replacement for removed structure_tensor_eigvals)
    Axx, Axy, Ayy = structure_tensor(img, sigma=1.0)
    tmp = np.sqrt((Axx - Ayy) ** 2 + 4 * Axy ** 2)
    l1 = 0.5 * (Axx + Ayy + tmp)
    l2 = 0.5 * (Axx + Ayy - tmp)

    coherence = (l1 - l2) / (l1 + l2 + 1e-8)
    coh_mean = _nan_safe(np.nanmean(coherence))
    coh_std  = _nan_safe(np.nanstd(coherence))

    return {
        "edge_sobel_mean": sob_mean,
        "edge_sobel_std": sob_std,
        "edge_ratio": edge_ratio,
        "grad_coherence_mean": coh_mean,
        "grad_coherence_std": coh_std,
    }


def _sharpness_features(img: np.ndarray) -> Dict[str, float]:
    lap = laplace(img, ksize=3)
    return {"sharp_lap_var": _nan_safe(lap.var())}


def _blob_calcification_features(img: np.ndarray) -> Dict[str, float]:
    img_eq = exposure.equalize_adapthist(img, clip_limit=0.01)
    blobs = blob_log(img_eq, min_sigma=1.2, max_sigma=3.5,
                     num_sigma=6, threshold=0.02)
    radii = (np.sqrt(2) * blobs[:, 2]).astype(np.float32) if blobs.size else np.array([], dtype=np.float32)

    h, w = img.shape[:2]
    area = float(h * w)
    count = int(len(radii))
    density = float(count / (area + 1e-8))

    return {
        "blob_count": float(count),
        "blob_density": density,
        "blob_radius_mean": _nan_safe(radii.mean() if count else 0.0),
        "blob_radius_std":  _nan_safe(radii.std() if count else 0.0),
    }


def _asymmetry_features(img: np.ndarray) -> Dict[str, float]:
    h, w = img.shape
    mid = w // 2
    left  = img[:, :mid]
    right = img[:, -mid:]
    right_flipped = np.fliplr(right)

    diff = np.abs(left - right_flipped)
    return {
        "asym_absdiff_mean": _nan_safe(diff.mean()),
        "asym_absdiff_std":  _nan_safe(diff.std()),
        "asym_mean_diff":    _nan_safe(left.mean() - right_flipped.mean()),
    }


def _shape_and_spiculation_features(img: np.ndarray) -> Dict[str, float]:
    feats = {
        "shape_area": 0.0, "shape_perimeter": 0.0,
        "shape_circularity": 0.0, "shape_eccentricity": 0.0,
        "shape_solidity": 0.0, "shape_extent": 0.0,
        "spic_edge_ring_ratio": 0.0, "spic_orient_dispersion": 0.0
    }

    try:
        thr = threshold_otsu(img)
        mask = img > thr
        mask = morphology.remove_small_objects(mask, min_size=500)
        labeled = measure.label(mask)
        regions = measure.regionprops(labeled)

        if not regions:
            return feats

        r = max(regions, key=lambda x: x.area)
        feats["shape_area"] = float(r.area)
        feats["shape_perimeter"] = float(r.perimeter)
        if r.perimeter > 0 and r.area > 0:
            feats["shape_circularity"] = float(4.0 * np.pi * r.area / (r.perimeter ** 2 + 1e-8))
        feats["shape_eccentricity"] = float(r.eccentricity)
        feats["shape_solidity"] = float(getattr(r, "solidity", 0.0))
        feats["shape_extent"] = float(getattr(r, "extent", 0.0))

        # Build a ring mask in full-image coordinates
        boundary = morphology.binary_dilation(r.image) ^ morphology.binary_erosion(r.image)
        ring = np.zeros_like(mask, dtype=bool)
        minr, minc, maxr, maxc = r.bbox
        ring[minr:maxr, minc:maxc] = boundary
        ring = morphology.binary_dilation(ring, morphology.disk(3))

        # Edge density inside ring
        sob = sobel(img)
        edge_bin = sob > (sob.mean() + sob.std())
        if ring.sum() > 50:
            feats["spic_edge_ring_ratio"] = float(edge_bin[ring].mean())

        # Orientation dispersion in ring (structure tensor)
        Axx, Axy, Ayy = structure_tensor(img, sigma=1.2)
        tmp = np.sqrt((Axx - Ayy) ** 2 + 4 * Axy ** 2)
        l1 = 0.5 * (Axx + Ayy + tmp)
        l2 = 0.5 * (Axx + Ayy - tmp)
        coherence = (l1 - l2) / (l1 + l2 + 1e-8)
        ring_coh = coherence[ring] if ring.any() else coherence
        feats["spic_orient_dispersion"] = _nan_safe(np.nanstd(ring_coh))
    except Exception:
        pass

    return feats


# -------------------------------------------------------------------------
# Main API
# -------------------------------------------------------------------------

def extract_image_features(image_path: str) -> Dict[str, float]:
    """
    Enhanced image feature extraction for mammograms.
    Includes adaptive contrast normalization, ROI masking, and normalized features.
    """
    img = _safe_load_grayscale(image_path)

    # 1) Adaptive contrast normalization (CLAHE)
    img = exposure.equalize_adapthist(img, clip_limit=0.02)

    # 2) ROI masking using Otsu threshold (ignore dark background)
    try:
        thr = threshold_otsu(img)
        roi_mask = img > thr
        if np.sum(roi_mask) > 1000:
            img = img * roi_mask
    except Exception:
        pass

    feats: Dict[str, float] = {}

    # 3) Core radiomic features
    feats.update(_glcm_features(img))
    feats.update(_histogram_features(img))
    feats.update(_edge_gradient_features(img))
    feats.update(_sharpness_features(img))
    feats.update(_blob_calcification_features(img))
    feats.update(_asymmetry_features(img))
    feats.update(_shape_and_spiculation_features(img))

    # 4) Extra spiculation metric (edge density near boundary)
    try:
        sob = sobel(img)
        thr = threshold_otsu(img)
        mask = img > thr
        mask = morphology.remove_small_objects(mask, min_size=500)
        labeled = measure.label(mask)
        regions = measure.regionprops(labeled)
        if regions:
            r = max(regions, key=lambda x: x.area)
            boundary = morphology.binary_dilation(r.image) ^ morphology.binary_erosion(r.image)
            ring = np.zeros_like(mask, dtype=bool)
            minr, minc, maxr, maxc = r.bbox
            ring[minr:maxr, minc:maxc] = boundary
            ring = morphology.binary_dilation(ring, morphology.disk(3))
            feats["spic_edge_density"] = float(np.mean(sob[ring])) if ring.sum() > 50 else 0.0
        else:
            feats["spic_edge_density"] = 0.0
    except Exception:
        feats["spic_edge_density"] = 0.0

    # 5) Directional GLCM variance (texture consistency across directions)
    try:
        im8 = (img * 255).astype(np.uint8)
        glcm_all = mahotas.features.haralick(im8, distance=1, ignore_zeros=True)
        feats["glcm_direction_var"] = float(np.var(glcm_all, axis=0).mean())
    except Exception:
        feats["glcm_direction_var"] = 0.0

    # 6) Shape normalization (relative to total image area)
    try:
        h, w = img.shape[:2]
        area = float(h * w)
        feats["shape_norm_area"] = feats.get("shape_area", 0.0) / (area + 1e-6)
    except Exception:
        feats["shape_norm_area"] = 0.0

    # 7) NaN/Inf guard
    for k, v in list(feats.items()):
        feats[k] = _nan_safe(v, 0.0)

    return feats
