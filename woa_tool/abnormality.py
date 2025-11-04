"""
Abnormality inference module for WOA-Tool.
Derives abnormality category, quantitative scores, and tissue characteristics
directly from radiomic z-scores of the input image.
"""

import numpy as np


def _clip01(x):
    """Clamp value between 0 and 1."""
    return float(max(0.0, min(1.0, round(x, 4))))


def infer_abnormality(zscores: dict):
    """
    Infer radiomic abnormality pattern and background tissue type.

    Args:
        zscores (dict): Radiomic feature z-scores from predict.py

    Returns:
        (abn_label, abn_scores, abn_expl, background)
    """

    if not zscores:
        return "Unknown", {}, "Insufficient data to infer abnormality.", {}

    # --- Retrieve essential z-score signals ---
    entropy = abs(float(zscores.get("glcm_entropy", 0)))
    contrast = abs(float(zscores.get("glcm_contrast", 0)))
    variance = abs(float(zscores.get("glcm_variance", 0)))
    shape_extent = abs(float(zscores.get("shape_extent", 0)))
    shape_ecc = abs(float(zscores.get("shape_eccentricity", 0)))
    spiculation = abs(float(zscores.get("spic_orient_dispersion", 0)))
    density = abs(float(zscores.get("hist_mean", 0)))

    # --- Quantify meaningful image-level indices ---
    texture_disorder = _clip01((entropy + contrast + variance / 1000.0) / 20.0)
    shape_irregularity = _clip01(((1 - shape_extent) + shape_ecc) / 2.0)
    spiculation_index = _clip01(spiculation / 3.0)
    density_index = _clip01(density / 5.0)

    abn_scores = {
        "texture_disorder": texture_disorder,
        "shape_irregularity": shape_irregularity,
        "spiculation_index": spiculation_index,
        "density_index": density_index
    }

    # --- Predict abnormality pattern ---
    if texture_disorder > 0.7 and shape_irregularity > 0.6:
        abn_label = "Invasive Malignant Pattern"
    elif spiculation_index > 0.6:
        abn_label = "Spiculated Lesion"
    elif texture_disorder > 0.5:
        abn_label = "Suspicious Texture Disorder"
    elif density_index > 0.5:
        abn_label = "Dense Tissue Area"
    else:
        abn_label = "Benign Pattern"

    # --- Background tissue estimation ---
    if density_index < 0.25:
        bg_code, bg_text = "T1", "Almost entirely fatty"
    elif density_index < 0.50:
        bg_code, bg_text = "T2", "Scattered fibroglandular densities"
    elif density_index < 0.75:
        bg_code, bg_text = "T3", "Heterogeneously dense tissue"
    else:
        bg_code, bg_text = "T4", "Extremely dense tissue"

    background = {
        "code": bg_code,
        "text": bg_text,
        "explain": (
            f"Background tissue density inferred from histogram mean "
            f"and radiomic intensity z-scores (density index = {density_index:.2f})."
        )
    }

    # --- Compute overall malignancy risk ---
    risk_score = np.mean(list(abn_scores.values()))
    if risk_score >= 0.7:
        risk_level = "High"
    elif risk_score >= 0.45:
        risk_level = "Moderate"
    else:
        risk_level = "Low"

    abn_expl = (
        f"Entropy={entropy:.2f}, Contrast={contrast:.2f}, "
        f"Shape Extent={shape_extent:.2f}, Spiculation={spiculation:.2f}. "
        f"Texture and shape irregularities indicate {abn_label.lower()}. "
        f"Estimated Risk Level: {risk_level}."
    )

    return abn_label, abn_scores, abn_expl, background
