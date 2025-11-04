# woa_tool/predict.py

import os, json
import numpy as np
from typing import Dict, List, Tuple

from .feature_extraction import extract_image_features
from .abnormality import infer_abnormality


def _load_model(model_path: str):
    with open(model_path, "r") as f:
        cfg = json.load(f)

    feature_names: List[str] = cfg["feature_names"]
    selected_idx: List[int] = cfg.get("selected_idx", list(range(len(feature_names))))

    # training normalization stats (used for test-time standardization)
    train_mu = np.array(cfg["train_mu"], dtype=float)
    train_sigma = np.array(cfg["train_sigma"], dtype=float)

    # class stats live in the standardized feature space
    mu_B = np.array(cfg["class_stats"]["0"]["mu"], dtype=float)
    mu_M = np.array(cfg["class_stats"]["1"]["mu"], dtype=float)

    # pooled inverse covariance (same standardized space)
    Sp_inv = np.array(cfg["Sp_inv"], dtype=float)

    # operating threshold
    tau = float(cfg["tau"])

    class_labels = {int(k): v for k, v in cfg["class_labels"].items()}

    return {
        "feature_names": feature_names,
        "selected_idx": np.array(selected_idx, dtype=int),
        "train_mu": train_mu,
        "train_sigma": train_sigma,
        "mu_B": mu_B,
        "mu_M": mu_M,
        "Sp_inv": Sp_inv,
        "tau": tau,
        "class_labels": class_labels,
    }


def _standardize_and_select(x_full: np.ndarray,
                            train_mu: np.ndarray,
                            train_sigma: np.ndarray,
                            selected_idx: np.ndarray) -> Tuple[np.ndarray, np.ndarray]:
    """Return (z_full, z_selected) where z = (x - mu) / sigma."""
    z_full = (x_full - train_mu) / (train_sigma + 1e-6)
    z_sel = z_full[selected_idx]
    return z_full, z_sel


def _mahalanobis_sq(v: np.ndarray, Sp_inv: np.ndarray) -> float:
    # v^T * Sp_inv * v (stable einsum)
    return float(np.einsum("i,ij,j->", v, Sp_inv, v))


def _predict_ratio(z_sel: np.ndarray,
                   mu_B: np.ndarray,
                   mu_M: np.ndarray,
                   Sp_inv: np.ndarray,
                   tau: float) -> Dict:
    """Return distances and hard decision using the same rule as training."""
    vB = z_sel - mu_B
    vM = z_sel - mu_M
    dB_sq = _mahalanobis_sq(vB, Sp_inv)
    dM_sq = _mahalanobis_sq(vM, Sp_inv)

    dB = np.sqrt(max(dB_sq, 0.0))
    dM = np.sqrt(max(dM_sq, 0.0))

    # Decision: malignant (1) if dM <= tau * dB else benign (0)
    yhat = 1 if (dM <= tau * dB) else 0

    # Soft-ish probabilities from inverse distances (purely for UI)
    invB = 1.0 / (dB + 1e-6)
    invM = 1.0 / (dM + 1e-6)
    Z = invB + invM
    probs = {"Benign": float(invB / Z), "Malignant": float(invM / Z)}

    return {"yhat": yhat, "dB": dB, "dM": dM, "probs": probs}


def _top_feature_contributors(z_sel: np.ndarray,
                              mu_ref: np.ndarray,
                              Sp_inv: np.ndarray,
                              selected_feature_names: List[str],
                              top_k: int = 5):
    """
    Heuristic per-feature contribution under Mahalanobis:
    contribution_i ≈ |v_i * (Sp_inv @ v)_i|
    where v = z - mu_ref.
    """
    v = z_sel - mu_ref
    Sv = Sp_inv @ v  # dual vector
    contrib = np.abs(v * Sv)
    s = float(np.sum(contrib) + 1e-12)
    contrib_norm = contrib / s if s > 0 else contrib

    pairs = list(zip(selected_feature_names, contrib_norm.tolist()))
    pairs.sort(key=lambda kv: kv[1], reverse=True)
    return pairs[:top_k]

import os, json
import numpy as np
from typing import Dict, List
from .feature_extraction import extract_image_features
from .abnormality import infer_abnormality


def predict(model_path: str, image_path: str, tau_override: float | None = None) -> Dict:
    """
    Predict class and infer abnormality for a new mammogram image using
    the EWOA Mahalanobis-ratio classifier trained via train_and_eval.py.
    """
    # === Load model ===
    with open(model_path, "r") as f:
        cfg = json.load(f)

    feature_names: List[str] = cfg["feature_names"]
    selected_idx: List[int] = cfg.get("selected_idx", list(range(len(feature_names))))
    selected_names = [feature_names[i] for i in selected_idx]

    train_mu = np.array(cfg["train_mu"], dtype=float)
    train_sigma = np.array(cfg["train_sigma"], dtype=float)
    mu_B = np.array(cfg["class_stats"]["0"]["mu"], dtype=float)
    mu_M = np.array(cfg["class_stats"]["1"]["mu"], dtype=float)
    Sp_inv = np.array(cfg["Sp_inv"], dtype=float)
    tau = float(cfg["tau"])

    if tau_override is not None:
        print(f"⚙️  τ override active → using τ = {tau_override}")
        tau = float(tau_override)

    # === Validate image path ===
    if not os.path.isfile(image_path):
        raise FileNotFoundError(f"❌ Image not found: {image_path}")

    # === Extract and normalize features ===
    feats_raw = extract_image_features(image_path)
    x_full = np.array([feats_raw.get(f, 0.0) for f in feature_names], dtype=float)
    x_norm = (x_full - train_mu) / (train_sigma + 1e-6)
    x = x_norm[selected_idx]

    # === Compute Mahalanobis distances ===
    zB = x - mu_B
    zM = x - mu_M
    dB = np.sqrt(zB @ Sp_inv @ zB)
    dM = np.sqrt(zM @ Sp_inv @ zM)

    # Decision rule
    pred_class = 1 if (dM <= tau * dB) else 0
    pred_label = "Malignant" if pred_class == 1 else "Benign"
    probs = {
        "Benign": float(1.0 / (1 + np.exp(- (dB - dM)))),
        "Malignant": float(1.0 / (1 + np.exp(- (dM - dB))))
    }

    # === z-scores (for abnormality inference) ===
    zvec = (x_full - train_mu) / (train_sigma + 1e-6)
    z = {name: float(zvec[i]) for i, name in enumerate(feature_names)}

    # === Infer abnormality and tissue type ===
    abn_label, abn_scores, abn_expl, background = infer_abnormality(z)

    # === Compute top contributors (abs z-distance from μ_M or μ_B) ===
    mu_ref = mu_M if pred_class == 1 else mu_B
    contrib_raw = np.abs(x - mu_ref)
    contrib_norm = contrib_raw / (np.sum(contrib_raw) + 1e-9)
    feature_contrib = {selected_names[j]: float(contrib_norm[j]) for j in range(len(selected_names))}
    top_features = sorted(feature_contrib.items(), key=lambda kv: kv[1], reverse=True)[:5]

    # === Return structured output ===
    result = {
        "final_prediction": pred_label,
        "probabilities": probs,
        "distance_to_benign": float(dB),
        "distance_to_malignant": float(dM),
        "tau": tau,
        "ratio_decision": f"Malignant if dM <= {tau:.3f} * dB else Benign",
        "abnormality_type": abn_label,
        "abnormality_scores": abn_scores if isinstance(abn_scores, dict) else {},
        "background_tissue": background,
        "explanation": {
            "class": [f"Mahalanobis ratio: dM <= {tau:.3f} * dB → {pred_label}"],
            "abnormality_summary": str(abn_expl)
        },
        "zscores": z,
        "top_feature_contributors": top_features
    }

    # Optional: structured lesion subtype parsing
    if "Mass" in abn_label or "Calcifications" in abn_label:
        lesion_subtype = {"category": None, "details": {}}
        if "Mass" in abn_label:
            lesion_subtype["category"] = "Mass"
        elif "Calcifications" in abn_label:
            lesion_subtype["category"] = "Calcifications"
        try:
            inner = abn_label.split("(")[1].split(")")[0]
            parts = [p.strip() for p in inner.split(",")]
            if lesion_subtype["category"] == "Mass" and len(parts) == 2:
                lesion_subtype["details"]["shape"], lesion_subtype["details"]["margin"] = parts
            elif lesion_subtype["category"] == "Calcifications" and len(parts) == 2:
                lesion_subtype["details"]["type"], lesion_subtype["details"]["distribution"] = parts
        except Exception:
            pass
        result["lesion_subtype"] = lesion_subtype

    return result
