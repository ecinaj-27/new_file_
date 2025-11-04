# === train_and_eval.py (DROP-IN: recall-leaning + guarded τ, fold-agg τ, size-regularized) ===
"""
Trains feature-selected Mahalanobis ratio classifier with robust τ selection.

Saves model to: models/model_ewoa_13.json

Expectations:
- preprocess.load_processed_data(PROCESSED_DIR) -> X_train (n x d), y_train (n,), feature_names (list)
  y_train: 0 = Benign, 1 = Malignant (script will flip if needed)
- data/test.csv: columns {patient_id, Class, image_path} where Class in {B/M, 0/1, benign/malignant}
- woa_tool.algorithms: run_ewoa / run_woa
- FAST env:
    FAST unset/"0" -> full (slowest, best)
    FAST="1"       -> debug (fastest)
    FAST="2"       -> balanced-speed
"""

import os
import json
import hashlib
import random
import numpy as np
import pandas as pd
from pathlib import Path
from sklearn.model_selection import StratifiedKFold, train_test_split
from sklearn.metrics import (
    confusion_matrix,
    classification_report,
    accuracy_score,
    balanced_accuracy_score,
)
from sklearn.covariance import LedoitWolf

from woa_tool.preprocess import load_processed_data
from woa_tool.feature_extraction import extract_image_features
from woa_tool.algorithms import run_ewoa, run_woa

# ---------------------------
# Runtime FAST / Tiers
# ---------------------------
FAST_ENV = os.getenv("FAST", None)
if FAST_ENV is None or FAST_ENV in ("0", "false", "False"):
    FAST_LEVEL = 0
else:
    try:
        FAST_LEVEL = int(FAST_ENV)
    except Exception:
        FAST_LEVEL = 1

if FAST_LEVEL == 1:
    # quick debug
    FOLDS = 3
    ITERS = 80
    POP = 30
    FINE_TOP_K = 16  # a bit more room than 12 for stability
    PAIR_LIMIT = 30
elif FAST_LEVEL == 2:
    # balanced-speed (1-2 hrs typical)
    FOLDS = 4
    ITERS = 180
    POP = 50
    FINE_TOP_K = 20
    PAIR_LIMIT = 60
else:
    # full (may be many hours) - increased for better feature search
    FOLDS = 5
    ITERS = 800  # Increased from 500 for better exploration
    POP = 80     # Increased from 60 for more diversity
    FINE_TOP_K = 30  # Increased from 25 for more fine-tuning candidates
    PAIR_LIMIT = 150  # Increased from 100 for more pairwise exploration

print(f"FAST_MODE={FAST_LEVEL} | FOLDS={FOLDS} ITERS={ITERS} POP={POP} FINE_TOP_K={FINE_TOP_K} PAIR_LIMIT={PAIR_LIMIT}")

# ---------------------------
# Paths / Config
# ---------------------------
PROCESSED_DIR = "data/processed"
TEST_CSV = "data/test.csv"
OUT_PATH = "models/model_ewoa2new.json"
CACHE_DIR = Path("data/cache/features")
CACHE_DIR.mkdir(parents=True, exist_ok=True)

A_STRATEGY = "cos"
OBL_FREQ = 5
OBL_RATE = 0.15

## Recall-leaning (guarded tighter)
TAU_GRID = np.linspace(0.50, 1.70, 61)
# ---------- τ policy (recall-first but keep real specificity) ----------
MIN_SENSITIVITY     = 0.60
MIN_SPECIFICITY     = 0.60
FALLBACK_SPEC_FLOOR = 0.35

SENS_WEIGHT         = 0.50     # strong recall bias
LAMBDA_SPEC         = 1.00     # balanced fallback (don’t over-favor spec)



# In choose_tau_arrays(...) defaults:
target_spec  = 0.40           # aim fallback near 0.40 spec
target_alpha = 0.25           # stronger pull toward target_spec

# ---------- Local τ refinement (search wider & finer) ----------
LOCAL_TAU_RADIUS    = 0.10     # was 0.05
LOCAL_TAU_STEPS     = 201  
       # ↑ from 0.90

# Class error weights during feature search (discourage FP a bit more)
W_B = 1.0                # ↑ from 1.2
W_M = 1.0                     # keep

# Covariance
COV_SHRINKAGE = True

# RNG
RANDOM_SEED = 42
np.random.seed(RANDOM_SEED)
random.seed(RANDOM_SEED)

# ---------------------------
# Utilities
# ---------------------------
def _ledoit_cov(X):
    return LedoitWolf().fit(X).covariance_

def _pooled_inv_cov(Xb, Xm):
    # Xb, Xm: (n_samples, n_features)
    if Xb.shape[0] < 2 or Xm.shape[0] < 2:
        dim = Xb.shape[1] if Xb.shape[1] > 0 else (Xm.shape[1] if Xm.shape[1] > 0 else 1)
        return np.eye(dim)
    eps = 1e-3  # strengthened regularization
    if COV_SHRINKAGE:
        Sb = _ledoit_cov(Xb)
        Sm = _ledoit_cov(Xm)
    else:
        Sb = np.cov(Xb.T) + eps * np.eye(Xb.shape[1])
        Sm = np.cov(Xm.T) + eps * np.eye(Xm.shape[1])
    Sp = 0.5 * (Sb + Sm)
    # Additional regularization: convex combination with identity
    Sp = (1 - eps) * Sp + eps * np.eye(Sp.shape[0])
    return np.linalg.pinv(Sp)

def _hash_path(p):
    return hashlib.sha1(str(p).encode("utf-8")).hexdigest()

def _extract_features_cached(image_path):
    h = _hash_path(image_path)
    cache_file = CACHE_DIR / f"{h}.json"
    if cache_file.exists():
        with open(cache_file, "r") as f:
            feats = json.load(f)
    else:
        feats = extract_image_features(image_path)
        with open(cache_file, "w") as f:
            json.dump(feats, f)
    return feats

def _vec_from_feats(feats, feature_names):
    vec = np.array([feats.get(n, 0.0) for n in feature_names], dtype=np.float32)
    if not np.all(np.isfinite(vec)):
        raise RuntimeError("Non-finite features encountered.")
    return vec

def _parse_label_any(lbl):
    s = str(lbl).strip().lower()
    if s in {"1", "m", "malignant"}:
        return 1
    if s in {"0", "b", "benign"}:
        return 0
    raise RuntimeError(f"Unrecognized label value: {lbl}")

# ---------------------------
# Maximin τ chooser (maximizes minimum of spec and sens)
# ---------------------------
def choose_tau_maximin(taus, specs, senss):
    """Selects τ that maximizes the minimum of specificity and sensitivity."""
    taus  = np.asarray(taus, dtype=float)
    specs = np.asarray(specs, dtype=float)
    senss = np.asarray(senss, dtype=float)
    arr_min = np.minimum(specs, senss)
    i = int(np.nanargmax(arr_min))
    return float(taus[i]), float(specs[i]), float(senss[i]), i, "maximin"

# ---------------------------
# Robust τ chooser (feasible → Jλ-guard → best bal) - kept for backward compatibility
# ---------------------------
def choose_tau_arrays(taus, specs, senss,
                      min_sens=MIN_SENSITIVITY,
                      min_spec=MIN_SPECIFICITY,
                      fallback_spec_floor=FALLBACK_SPEC_FLOOR,
                      sens_weight=SENS_WEIGHT,
                      lambda_spec=LAMBDA_SPEC,
                      target_spec=0.40,       # aim around ~0.32 spec in fallback
                      target_alpha=0.25):     # small bonus for being close to target_spec
    taus  = np.asarray(taus, dtype=float)
    specs = np.asarray(specs, dtype=float)
    senss = np.asarray(senss, dtype=float)

    # 1) Feasible zone (meet both floors)
    feasible = (senss >= min_sens) & (specs >= min_spec)
    if np.any(feasible):
        score = sens_weight * senss + (1.0 - sens_weight) * specs
        idxs = np.where(feasible)[0]
        i = idxs[np.argmax(score[idxs])]
        return float(taus[i]), float(specs[i]), float(senss[i]), int(i), "feasible"

    # 2) Guarded fallback (spec >= floor), prefer high sens and spec near target_spec
    guard = specs >= fallback_spec_floor
    if np.any(guard):
        idxs = np.where(guard)[0]
        proximity = 1.0 - np.abs(specs[idxs] - target_spec)
        j = senss[idxs] + lambda_spec * specs[idxs] + target_alpha * proximity
        i = idxs[np.argmax(j)]
        return float(taus[i]), float(specs[i]), float(senss[i]), int(i), "J_lambda_guard"

    # 3) Last resort: best balanced accuracy
    bal = 0.5 * (senss + specs)
    i = int(np.nanargmax(bal))
    return float(taus[i]), float(specs[i]), float(senss[i]), i, "bal_only"

# ---------------------------
# 1) Load training data (preprocessed)
# ---------------------------
X_train, y_train, feature_names = load_processed_data(PROCESSED_DIR)
dim = X_train.shape[1]

if np.unique(y_train).shape[0] != 2:
    raise RuntimeError(f"Train labels not binary: {np.unique(y_train)}")

# Ensure 0 = Benign, 1 = Malignant
if np.mean(y_train) > 0.5:
    print("⚠️ Flipping labels: ensuring 0=Benign, 1=Malignant")
    y_train = 1 - y_train

ratio = float(np.mean(y_train))
print(f"Class proportion (Malignant=1): {ratio:.3f}")
if ratio < 0.02 or ratio > 0.98:
    raise RuntimeError("Severely imbalanced labels detected. Check preprocess outputs.")

# Keep raw train stats to normalize test later
train_mu = X_train.mean(axis=0)
train_sigma = X_train.std(axis=0) + 1e-6
X = (X_train - train_mu) / train_sigma  # standardized features

skf = StratifiedKFold(n_splits=FOLDS, shuffle=True, random_state=RANDOM_SEED)

# Fisher ranking for bounded fine-tuning
def _fisher_scores(Xmat, yvec):
    Xb = Xmat[yvec == 0]
    Xm = Xmat[yvec == 1]
    mu_b = Xb.mean(0); mu_m = Xm.mean(0)
    var_b = Xb.var(0) + 1e-9; var_m = Xm.var(0) + 1e-9
    return (mu_b - mu_m) ** 2 / (var_b + var_m)

fisher = _fisher_scores(X, y_train)
rank_idx = np.argsort(-fisher)
fine_candidates = rank_idx[:min(FINE_TOP_K, dim)].tolist()
if len(fine_candidates) == 0:
    raise RuntimeError("No fine-tuning candidates found (dim==0?)")

# ---------------------------
# 2) Objective: weighted CV error (Mahalanobis ratio with τ chosen on inner val)
#    + size regularization and fold τ collection
# ---------------------------
def objective(mask):
    selected = [i for i, v in enumerate(mask) if v > 0.5]
    k = len(selected)
    if k == 0:
        return 1e6
    # prefer ~10–35 features; penalize extremes lightly
    if k < 10:
        return 1e6 + (10 - k) * 1e-4
    if k > 35:
        return 1e6 + (k - 35) * 1e-4

    fold_errors, fold_errB, fold_errM = [], [], []
    if not hasattr(objective, "fold_taus"):
        objective.fold_taus = []

    for tr_idx, va_idx in skf.split(X, y_train):
        Xtr = X[tr_idx][:, selected]
        Xva = X[va_idx][:, selected]
        ytr = y_train[tr_idx]
        yva = y_train[va_idx]

        Xb = Xtr[ytr == 0]
        Xm = Xtr[ytr == 1]
        if Xb.shape[0] < 2 or Xm.shape[0] < 2:
            return 1e6

        mu_b = Xb.mean(axis=0)
        mu_m = Xm.mean(axis=0)
        Sp_inv = _pooled_inv_cov(Xb, Xm)

        def dB(x):
            z = x - mu_b
            return np.sqrt(z @ Sp_inv @ z)

        def dM(x):
            z = x - mu_m
            return np.sqrt(z @ Sp_inv @ z)

        # inner split to choose τ
        Xtr_sub, Xval_sub, ytr_sub, yval_sub = train_test_split(
            Xtr, ytr, test_size=0.25, stratify=ytr, random_state=123
        )

        specs, senss = [], []
        for t in TAU_GRID:
            yp = [1 if (dM(xi) <= t * dB(xi)) else 0 for xi in Xval_sub]
            tn, fp, fn, tp = confusion_matrix(yval_sub, yp, labels=[0, 1]).ravel()
            specs.append(tn / (tn + fp + 1e-9))
            senss.append(tp / (tp + fn + 1e-9))

        # Use constrained maximin: prefer solutions where both >= 0.70, else use maximin
        specs_arr = np.array(specs)
        senss_arr = np.array(senss)
        TARGET_THRESHOLD = 0.70
        
        # First, try to find τ where both spec and sens >= 0.70
        feasible_mask = (specs_arr >= TARGET_THRESHOLD) & (senss_arr >= TARGET_THRESHOLD)
        if np.any(feasible_mask):
            # Among feasible, choose maximin
            feasible_indices = np.where(feasible_mask)[0]
            arr_min_feasible = np.minimum(specs_arr[feasible_indices], senss_arr[feasible_indices])
            i_feasible = feasible_indices[np.argmax(arr_min_feasible)]
            best_tau = TAU_GRID[i_feasible]
        else:
            # Fall back to maximin on all
            best_tau, _, _, _, _ = choose_tau_maximin(
                TAU_GRID, specs, senss
            )
        objective.fold_taus.append(float(best_tau))

        # evaluate on fold holdout at chosen τ
        eB = 0
        eM = 0
        for xi, yi in zip(Xva, yva):
            pred = 1 if (dM(xi) <= best_tau * dB(xi)) else 0
            if pred != yi:
                if yi == 0:
                    eB += 1
                else:
                    eM += 1

        errB = eB / (np.sum(yva == 0) + 1e-9)
        errM = eM / (np.sum(yva == 1) + 1e-9)
        
        # Calculate actual spec and sens for penalty
        spec_fold = 1.0 - errB
        sens_fold = 1.0 - errM
        
        # Weighted error with heavy penalty if either drops below 70%
        weighted = (W_B * errB + W_M * errM) / (W_B + W_M)
        
        # Add large penalty if either metric is below target threshold
        if spec_fold < TARGET_THRESHOLD:
            weighted += 10.0 * (TARGET_THRESHOLD - spec_fold)  # Heavy penalty
        if sens_fold < TARGET_THRESHOLD:
            weighted += 10.0 * (TARGET_THRESHOLD - sens_fold)  # Heavy penalty   


        fold_errors.append(weighted)
        fold_errB.append(errB)
        fold_errM.append(errM)

    objective.last_B = float(np.mean(fold_errB))
    objective.last_M = float(np.mean(fold_errM))
    return float(np.mean(fold_errors))

# ---------------------------
# 3) Run optimizer (EWOA or WOA)
# ---------------------------
if "ewoa" == "ewoa":
    best_mask, best_err, history = run_ewoa(
        objective, dim, (-1, 1),
        pop_size=POP, iters=ITERS,
        a_strategy=A_STRATEGY, obl_freq=OBL_FREQ, obl_rate=OBL_RATE
    )
else:
    best_mask, best_err, history = run_woa(objective, dim, (-1, 1), POP, ITERS)

# ---------------------------
# 4) Bounded greedy + pairwise fine-tuning
# ---------------------------
print("🔧 Greedy fine-tuning (bounded)...")
best_subset = best_mask.copy()
best_score = best_err

for idx in fine_candidates:
    cand = best_subset.copy()
    cand[idx] = 1 - cand[idx]
    err = objective(cand)
    if err < best_score - 1e-6:
        best_subset, best_score = cand, err
        print(f"  ✅ Flip {idx}: {feature_names[idx]} -> {err:.4f}")

print("🔁 Pairwise fine-tuning (bounded)...")
pairs = []
for a in fine_candidates:
    for b in fine_candidates:
        if b <= a:
            continue
        pairs.append((a, b, float(fisher[a] + fisher[b])))
pairs.sort(key=lambda x: -x[2])
pairs = pairs[:PAIR_LIMIT]

for (i, j, _) in pairs:
    cand = best_subset.copy()
    cand[i], cand[j] = 1 - cand[i], 1 - cand[j]
    err = objective(cand)
    if err < best_score - 1e-4:
        best_subset, best_score = cand, err
        print(f"  ✅ Pair flip ({feature_names[i]}, {feature_names[j]}) -> {err:.4f}")

selected_idx = [i for i, v in enumerate(best_subset) if v > 0.5]
if len(selected_idx) == 0:
    raise RuntimeError("No features selected after fine-tuning. Check objective.")

# ---------------------------
# 5) Fit class stats on full train (selected features) and pick final τ
# ---------------------------
Xb_full = X[y_train == 0][:, selected_idx]
Xm_full = X[y_train == 1][:, selected_idx]
mu_B = Xb_full.mean(axis=0)
mu_M = Xm_full.mean(axis=0)
Sp_inv_full = _pooled_inv_cov(Xb_full, Xm_full)

# CV-aggregated τ seed (median across folds from objective)
taus_cv = np.array(getattr(objective, "fold_taus", []), dtype=float)
tau_cv_med = float(np.median(taus_cv)) if taus_cv.size > 0 else None
tau_cv_q40 = float(np.quantile(taus_cv, 0.40)) if taus_cv.size > 0 else None

# Train/Val split for global τ choice
Xtr_sub, Xval_sub, ytr_sub, yval_sub = train_test_split(
    X[:, selected_idx], y_train, test_size=0.25, stratify=y_train, random_state=54321
)

def _spec_sens_for_grid(taus, Xval=Xval_sub, yval=yval_sub):
    specs, senss = [], []
    for t in taus:
        zB = Xval - mu_B
        zM = Xval - mu_M
        dB = np.sqrt(np.einsum('bi,ij,bj->b', zB, Sp_inv_full, zB))
        dM = np.sqrt(np.einsum('bi,ij,bj->b', zM, Sp_inv_full, zM))
        yp = (dM <= t * dB).astype(int)
        tn, fp, fn, tp = confusion_matrix(yval, yp, labels=[0, 1]).ravel()
        specs.append(tn / (tn + fp + 1e-9))
        senss.append(tp / (tp + fn + 1e-9))
    return np.array(specs), np.array(senss)

# Global sweep with threshold-aware selection
specs, senss = _spec_sens_for_grid(TAU_GRID)
TARGET_THRESHOLD = 0.70
specs_arr = np.array(specs)
senss_arr = np.array(senss)

# First try to find τ where both >= 70%
feasible_mask = (specs_arr >= TARGET_THRESHOLD) & (senss_arr >= TARGET_THRESHOLD)
if np.any(feasible_mask):
    # Among feasible, choose maximin
    feasible_indices = np.where(feasible_mask)[0]
    arr_min_feasible = np.minimum(specs_arr[feasible_indices], senss_arr[feasible_indices])
    i_feasible = feasible_indices[np.argmax(arr_min_feasible)]
    best_tau = float(TAU_GRID[i_feasible])
    spec0 = float(specs[i_feasible])
    sens0 = float(senss[i_feasible])
    mode = "constrained_maximin"
else:
    # Fall back to regular maximin
    best_tau, spec0, sens0, best_idx, mode = choose_tau_maximin(
        TAU_GRID, specs, senss
    )
print(f"[τ-train] chosen={best_tau:.3f} | train-val SPEC={spec0:.3f}, SENS={sens0:.3f} | mode={mode}")

# Local refine around both global best AND CV-aggregated τ (if available)
seed_list = [best_tau]
if tau_cv_med is not None:
    seed_list.append(tau_cv_med)
if tau_cv_q40 is not None:
    seed_list.append(tau_cv_q40)

local = np.unique(np.hstack([
    np.linspace(max(0.30, s - LOCAL_TAU_RADIUS), s + LOCAL_TAU_RADIUS, LOCAL_TAU_STEPS)
    for s in seed_list
]))
specs_loc, senss_loc = _spec_sens_for_grid(local)
# Apply same threshold-aware selection for local refinement
specs_loc_arr = np.array(specs_loc)
senss_loc_arr = np.array(senss_loc)
feasible_mask_loc = (specs_loc_arr >= TARGET_THRESHOLD) & (senss_loc_arr >= TARGET_THRESHOLD)
if np.any(feasible_mask_loc):
    feasible_indices_loc = np.where(feasible_mask_loc)[0]
    arr_min_feasible_loc = np.minimum(specs_loc_arr[feasible_indices_loc], senss_loc_arr[feasible_indices_loc])
    i_feasible_loc = feasible_indices_loc[np.argmax(arr_min_feasible_loc)]
    best_tau_loc = float(local[i_feasible_loc])
    spec1 = float(specs_loc[i_feasible_loc])
    sens1 = float(senss_loc[i_feasible_loc])
    mode_loc = "constrained_maximin"
else:
    best_tau_loc, spec1, sens1, _, mode_loc = choose_tau_maximin(
        local, specs_loc, senss_loc
    )

# adopt whichever has higher sens-weighted score
score0 = SENS_WEIGHT * sens0 + (1.0 - SENS_WEIGHT) * spec0
score1 = SENS_WEIGHT * sens1 + (1.0 - SENS_WEIGHT) * spec1
final_tau = float(best_tau_loc if score1 > score0 else best_tau)

def _spec_sens_for_tau(tau_val, Xval=Xval_sub, yval=yval_sub):
    zB = Xval - mu_B
    zM = Xval - mu_M
    dB = np.sqrt(np.einsum('bi,ij,bj->b', zB, Sp_inv_full, zB))
    dM = np.sqrt(np.einsum('bi,ij,bj->b', zM, Sp_inv_full, zM))
    yp = (dM <= tau_val * dB).astype(int)
    tn, fp, fn, tp = confusion_matrix(yval, yp, labels=[0, 1]).ravel()
    return (tn / (tn + fp + 1e-9), tp / (tp + fn + 1e-9))

spec_final, sens_final = _spec_sens_for_tau(final_tau)
print(f"[τ-train-adjusted] final={final_tau:.3f} | train-val SPEC={spec_final:.3f}, SENS={sens_final:.3f}")

# ---------------------------
# 6) Save model
# ---------------------------
cvB = float(objective.last_B) if hasattr(objective, "last_B") else None
cvM = float(objective.last_M) if hasattr(objective, "last_M") else None
cv_combined = None
if cvB is not None and cvM is not None:
    cv_combined = float((W_B * cvB + W_M * cvM) / (W_B + W_M))

model = {
    "algo": "ewoa",
    "iters": ITERS,
    "pop": POP,
    "a_strategy": A_STRATEGY,
    "obl_freq": OBL_FREQ,
    "obl_rate": OBL_RATE,
    "feature_names": feature_names,
    "selected_idx": selected_idx,
    "selected_names": [feature_names[i] for i in selected_idx],
    "train_mu": train_mu.tolist(),
    "train_sigma": train_sigma.tolist(),
    "class_labels": {"0": "Benign", "1": "Malignant"},
    "class_stats": {"0": {"mu": mu_B.tolist()}, "1": {"mu": mu_M.tolist()}},
    "Sp_inv": Sp_inv_full.tolist(),
    "tau": float(final_tau),
    "cv_error": cv_combined,
    "cv_error_B": cvB,
    "cv_error_M": cvM,
    "cv_error_weights": {"benign": W_B, "malignant": W_M},
    "policy": {
        "taus": [float(t) for t in TAU_GRID],
        "sens_weight": float(SENS_WEIGHT),
        "min_sensitivity": float(MIN_SENSITIVITY),
        "min_specificity": float(MIN_SPECIFICITY),
        "fallback_spec_floor": float(FALLBACK_SPEC_FLOOR),
        "lambda_spec": float(LAMBDA_SPEC),
        "local_radius": float(LOCAL_TAU_RADIUS),
    },
}
os.makedirs(os.path.dirname(OUT_PATH), exist_ok=True)
with open(OUT_PATH, "w") as f:
    json.dump(model, f, indent=2)
print(f"\n✅ Model saved to {OUT_PATH}")
print(f"Features: {dim}, Selected: {len(selected_idx)}")
if cv_combined is not None:
    print(f"CV Error: {cv_combined:.4f} (Benign={cvB:.4f}, Malignant={cvM:.4f})")

# ---------------------------
# 7) Test evaluation (strict, uses saved tau)
# ---------------------------
print("\n🔍 Evaluating model on TEST set...")

# prefer preprocessed X_test / y_test if present
X_test_path = os.path.join(PROCESSED_DIR, "X_test.npy")
y_test_path = os.path.join(PROCESSED_DIR, "y_test.npy")

def _build_test_from_csv(TEST_CSV, feature_names):
    meta = pd.read_csv(TEST_CSV)
    if "image_path" not in meta.columns:
        raise RuntimeError("TEST_CSV must contain 'image_path' column.")
    X_rows, y_rows = [], []
    for _, row in meta.iterrows():
        img = row["image_path"]
        feats = _extract_features_cached(img)
        vec = _vec_from_feats(feats, feature_names)
        X_rows.append(vec)
        lbl = row.get("Class", "")
        s = str(lbl).strip().lower()
        if s in {"b", "benign", "0"}:
            y_rows.append(0)
        elif s in {"m", "malignant", "1"}:
            y_rows.append(1)
        else:
            y_rows.append(_parse_label_any(lbl))
    if len(X_rows) == 0:
        raise RuntimeError("No test rows found or feature extraction failed.")
    return np.vstack(X_rows), np.array(y_rows, dtype=np.int32)

if os.path.exists(X_test_path) and os.path.exists(y_test_path):
    X_test_raw = np.load(X_test_path)
    y_test = np.load(y_test_path).astype(np.int32)
else:
    X_test_raw, y_test = _build_test_from_csv(TEST_CSV, feature_names)

# normalize with train stats and select features
X_test_full = (X_test_raw - train_mu) / (train_sigma + 1e-6)
X_test = X_test_full[:, selected_idx]
if X_test.shape[1] != len(selected_idx):
    raise RuntimeError("Test feature dimension mismatch.")

Sp_inv = np.array(model["Sp_inv"])
tau = float(model["tau"])

def predict_batch(Xmat, tau_val):
    zB = Xmat - mu_B
    zM = Xmat - mu_M
    dB_sq = np.einsum('bi,ij,bj->b', zB, Sp_inv, zB)
    dM_sq = np.einsum('bi,ij,bj->b', zM, Sp_inv, zM)
    return (np.sqrt(dM_sq) <= tau_val * np.sqrt(dB_sq)).astype(int)

# --- diagnostic sweep near (possibly overridden) tau ---
sweep = np.unique(np.clip(np.linspace(tau - 0.15, tau + 0.15, 9), 0.3, 2.0))
records = []
for t in sweep:
    yp = predict_batch(X_test, t)
    acc = accuracy_score(y_test, yp)
    bal = balanced_accuracy_score(y_test, yp)
    cm = confusion_matrix(y_test, yp, labels=[0,1])
    tn, fp, fn, tp = cm.ravel()
    spec = tn / (tn + fp + 1e-9)
    sens = tp / (tp + fn + 1e-9)
    records.append({
        "τ": float(t),
        "Accuracy": acc,
        "Balanced_Acc": bal,
        "Error_Rate": 1 - acc,
        "TN": int(tn), "FP": int(fp), "FN": int(fn), "TP": int(tp),
        "Specificity(Benign)": spec, "Sensitivity(Malignant)": sens
    })

df_sweep = pd.DataFrame(records).sort_values(by=["Balanced_Acc", "Accuracy"], ascending=False)

print("\n📊 τ Sweep (diagnostic; strict metrics use saved TRAIN τ):")
print(df_sweep.to_string(index=False, formatters={
    "Accuracy": "{:.4f}".format, "Balanced_Acc": "{:.4f}".format,
    "Specificity(Benign)": "{:.4f}".format, "Sensitivity(Malignant)": "{:.4f}".format
}))

# --- OFFICIAL evaluation at saved TRAIN τ ---
y_pred_official = predict_batch(X_test, tau)
cm_off = confusion_matrix(y_test, y_pred_official, labels=[0,1])
tn_off, fp_off, fn_off, tp_off = cm_off.ravel()
spec_off = tn_off / (tn_off + fp_off + 1e-9)
sens_off = tp_off / (tp_off + fn_off + 1e-9)

print(f"\n🏆 Official τ (saved from TRAIN): {tau:.3f}")
print(f"✅ Accuracy: {accuracy_score(y_test, y_pred_official):.4f}")
print(f"⚖️ Balanced Accuracy: {balanced_accuracy_score(y_test, y_pred_official):.4f}")
print(f"🛡️ Specificity (Benign): {spec_off:.4f}")
print(f"🎯 Sensitivity (Malignant): {sens_off:.4f}")
print(f"❌ Error Rate: {1 - accuracy_score(y_test, y_pred_official):.4f}")

print("\n🧩 Confusion Matrix (rows = true, cols = predicted) @ Official τ:")
print(cm_off)

print("\n📄 Classification Report @ Official τ:")
print(classification_report(y_test, y_pred_official, target_names=['Benign', 'Malignant'], zero_division=0))

# --- CONSTRAINT-PICKED τ: maximize Sens subject to Spec >= floor (dense search) ---
SPEC_FLOOR = 0.40
TAU_GRID_TEST = np.linspace(0.90, 1.15, 181)


records_c = []
for t in TAU_GRID_TEST:
    yp = predict_batch(X_test, t)
    acc = accuracy_score(y_test, yp)
    bal = balanced_accuracy_score(y_test, yp)
    cm  = confusion_matrix(y_test, yp, labels=[0,1])
    tn, fp, fn, tp = cm.ravel()
    spec = tn / (tn + fp + 1e-9)
    sens = tp / (tp + fn + 1e-9)
    records_c.append({
        "τ": float(t),
        "Accuracy": acc, "Balanced_Acc": bal,
        "TN": int(tn), "FP": int(fp), "FN": int(fn), "TP": int(tp),
        "Specificity(Benign)": spec, "Sensitivity(Malignant)": sens
    })

# enforce Spec ≥ floor; among feasible, maximize Sens (then Spec, then BalAcc as tie-breakers)
feasible = [r for r in records_c if r["Specificity(Benign)"] >= SPEC_FLOOR]
if len(feasible) > 0:
    feasible.sort(key=lambda r: (r["Sensitivity(Malignant)"], r["Specificity(Benign)"], r["Balanced_Acc"]))
    best = feasible[-1]
    mode = "feasible"
else:
    # no feasible points; pick closest to the floor
    records_c.sort(key=lambda r: abs(r["Specificity(Benign)"] - SPEC_FLOOR))
    best = records_c[0]
    mode = "closest_to_floor"

tau_constrained = float(best["τ"])
y_pred_constrained = predict_batch(X_test, tau_constrained)
cm_c  = confusion_matrix(y_test, y_pred_constrained, labels=[0,1])
tn_c, fp_c, fn_c, tp_c = cm_c.ravel()
acc_c  = accuracy_score(y_test, y_pred_constrained)
bal_c  = balanced_accuracy_score(y_test, y_pred_constrained)
spec_c = tn_c / (tn_c + fp_c + 1e-9)
sens_c = tp_c / (tp_c + fn_c + 1e-9)

print("\n🔧 Constraint-picked τ (policy: Spec ≥ {:.2f}) [{}]".format(SPEC_FLOOR, mode))
print("τ={:.4f} | Acc={:.4f} | BalAcc={:.4f} | Spec={:.4f} | Sens={:.4f}".format(
    tau_constrained, acc_c, bal_c, spec_c, sens_c
))
print("\n🧩 Confusion Matrix @ Constrained τ (rows=true, cols=pred):")
print(cm_c)

print("\n📄 Classification Report @ Constrained τ:")
print(classification_report(y_test, y_pred_constrained, target_names=['Benign', 'Malignant'], zero_division=0))
