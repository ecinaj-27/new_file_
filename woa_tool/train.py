import os, json, numpy as np
from sklearn.model_selection import StratifiedKFold
from .preprocess import load_processed_data
from .algorithms import run_ewoa, run_woa

# ===============================================================
#  TRAIN MODULE — Mahalanobis-based EWOA Feature Selection
# ===============================================================

def train(processed_dir="data/processed",
          algo="ewoa",
          iters=500,
          pop=80,
          a_strategy="cos",
          obl_freq=5,
          obl_rate=0.15,
          out="models/model_ewoa_final3.json",
          folds=5):

    # === Load preprocessed features and labels ===
    X, y, feature_names = load_processed_data(processed_dir)
    dim = X.shape[1]

    # === Normalize labels to 0=Benign, 1=Malignant ===
    if np.mean(y) > 0.5:
        print("⚠️ Flipping labels: ensuring 0=Benign, 1=Malignant")
        y = 1 - y

    # === Z-score normalization ===
    X = (X - X.mean(axis=0)) / (X.std(axis=0) + 1e-6)
    global_mu, global_sigma = X.mean(axis=0), X.std(axis=0) + 1e-6

    skf = StratifiedKFold(n_splits=folds, shuffle=True, random_state=42)

    # ===========================================================
    #  Objective function (feature-subset fitness)
    # ===========================================================
    def objective(mask):
        selected = [i for i, v in enumerate(mask) if v > 0.5]
        if not selected:
            return 1e6  # discourage empty subset

        fold_errors, fold_B, fold_M = [], [], []

        for tr, va in skf.split(X, y):
            Xtr, Xva = X[tr][:, selected], X[va][:, selected]
            ytr, yva = y[tr], y[va]

            mu_b = Xtr[ytr == 0].mean(axis=0)
            mu_m = Xtr[ytr == 1].mean(axis=0)

            Sb = np.cov(Xtr[ytr == 0].T) + 1e-6 * np.eye(len(selected))
            Sm = np.cov(Xtr[ytr == 1].T) + 1e-6 * np.eye(len(selected))
            Sp_inv = np.linalg.pinv(0.5 * (Sb + Sm))

            def maha(x, mu):
                d = x - mu
                return float(np.sqrt(d @ Sp_inv @ d))

            # === τ sweep ===
            best_err, best_tau = np.inf, 1.0
            taus = [0.90, 0.95, 0.98, 1.00, 1.02, 1.05, 1.08, 1.10, 1.12]

            for T in taus:
                eB = eM = 0
                for xi, yi in zip(Xva, yva):
                    d_b, d_m = maha(xi, mu_b), maha(xi, mu_m)
                    pred = 1 if (d_m / (d_b + 1e-9)) < T else 0
                    if pred != yi:
                        if yi == 0: eB += 1
                        else: eM += 1
                errB = eB / (np.sum(yva == 0) + 1e-6)
                errM = eM / (np.sum(yva == 1) + 1e-6)
                W_B, W_M = 1.0, 1.5
                weighted = (W_B * errB + W_M * errM) / (W_B + W_M)
                if weighted < best_err:
                    best_err, best_tau = weighted, T

            # === Final errors with best τ ===
            eB = eM = 0
            for xi, yi in zip(Xva, yva):
                d_b, d_m = maha(xi, mu_b), maha(xi, mu_m)
                pred = 1 if (d_m / (d_b + 1e-9)) < best_tau else 0
                if pred != yi:
                    if yi == 0: eB += 1
                    else: eM += 1

            errB = eB / (np.sum(yva == 0) + 1e-6)
            errM = eM / (np.sum(yva == 1) + 1e-6)
            weighted_err = (1.0 * errB + 1.5 * errM) / 2.5

            # === Size penalty & diversity reward ===
            k, target, alpha = len(selected), 17, 0.008
            weighted_err += alpha * abs(k - target) / max(1, X.shape[1])

            fold_errors.append(weighted_err)
            fold_B.append(errB)
            fold_M.append(errM)

        objective.last_B = float(np.mean(fold_B))
        objective.last_M = float(np.mean(fold_M))
        return float(np.mean(fold_errors))

    # ===========================================================
    #  Run EWOA optimizer
    # ===========================================================
    if algo.lower() == "ewoa":
        best_mask, best_err, hist = run_ewoa(
            objective, dim, (-1, 1),
            pop_size=pop, iters=iters,
            a_strategy=a_strategy,
            obl_freq=obl_freq,
            obl_rate=obl_rate
        )
    else:
        best_mask, best_err, hist = run_woa(objective, dim, (-1, 1), pop, iters)

    # ===========================================================
    #  Greedy fine-tuning (single + pairwise)
    # ===========================================================
    print("🔧 Greedy post-optimization fine-tuning...")
    best_subset, best_score = best_mask.copy(), best_err
    for i in range(dim):
        candidate = best_subset.copy(); candidate[i] = 1 - candidate[i]
        err = objective(candidate)
        if err < best_score:
            best_subset, best_score = candidate, err
            print(f"  ✅ Flip {i}: {feature_names[i]} → {err:.4f}")
    best_mask = best_subset

    print("🔁 Second-pass pairwise fine-tuning...")
    for i in range(dim):
        for j in range(i + 1, dim):
            cand = best_mask.copy()
            cand[i], cand[j] = 1 - cand[i], 1 - cand[j]
            err = objective(cand)
            if err < best_score - 1e-4:
                best_mask, best_score = cand, err
                print(f"  ✅ Pair flip ({feature_names[i]}, {feature_names[j]}) → {err:.4f}")

    # ===========================================================
    #  Save final model
    # ===========================================================
    selected_idx = [i for i, v in enumerate(best_mask) if v > 0.5]
    mu_B = X[y == 0][:, selected_idx].mean(axis=0)
    mu_M = X[y == 1][:, selected_idx].mean(axis=0)
    sigma_B = X[y == 0][:, selected_idx].std(axis=0)
    sigma_M = X[y == 1][:, selected_idx].std(axis=0)

    model = {
        "algo": algo,
        "iters": iters,
        "pop": pop,
        "a_strategy": a_strategy,
        "obl_freq": obl_freq,
        "obl_rate": obl_rate,
        "feature_names": feature_names,
        "selected_idx": selected_idx,
        "selected_names": [feature_names[i] for i in selected_idx],
        "global_mu": global_mu.tolist(),
        "global_sigma": global_sigma.tolist(),
        "class_labels": {0: "Benign", 1: "Malignant"},
        "class_stats": {
            0: {"mu": mu_B.tolist(), "sigma": sigma_B.tolist()},
            1: {"mu": mu_M.tolist(), "sigma": sigma_M.tolist()}
        },
        "cv_error": float(best_score),
        "cv_error_B": float(objective.last_B),
        "cv_error_M": float(objective.last_M)
    }

    os.makedirs(os.path.dirname(out), exist_ok=True)
    with open(out, "w") as f:
        json.dump(model, f, indent=2)

    print(f"✅ Model saved to {out}")
    print(f"Features: {dim}, Selected: {len(selected_idx)}")
    print(f"CV Error: {best_score:.4f} "
          f"(Benign err={objective.last_B:.4f}, Malignant err={objective.last_M:.4f})")

    return model
