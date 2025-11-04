# woa_tool/preprocess.py
import os
import json
import numpy as np
import pandas as pd

from .feature_extraction import extract_image_features

OUT_DIR = "data/processed"
os.makedirs(OUT_DIR, exist_ok=True)

# Accept broader label forms; normalize to {0: Benign, 1: Malignant}
_LABEL_MAP = {
    "b": 0, "benign": 0, "0": 0, 0: 0,
    "m": 1, "malignant": 1, "1": 1, 1: 1,
    "B": 0, "M": 1  # keep legacy uppercase too
}

def _normalize_label(x):
    s = str(x).strip().lower()
    if s in _LABEL_MAP:
        return int(_LABEL_MAP[s])
    raise ValueError(f"Unrecognized label value: {x!r} (expected B/M, 0/1, benign/malignant)")

def load_processed_data(processed_dir="data/processed"):
    """
    Load preprocessed feature arrays and feature names from disk.
    Returns:
        X (np.ndarray): feature matrix
        y (np.ndarray): label vector (0=Benign, 1=Malignant)
        feature_names (list[str])
    """
    X_path = os.path.join(processed_dir, "X_train.npy")
    y_path = os.path.join(processed_dir, "y_train.npy")
    features_path = os.path.join(processed_dir, "feature_names.json")

    if not (os.path.exists(X_path) and os.path.exists(y_path) and os.path.exists(features_path)):
        raise FileNotFoundError(
            f"❌ Missing processed data in {processed_dir}. "
            f"Run 'python3 -m woa_tool.cli preprocess' first."
        )

    X = np.load(X_path)
    y = np.load(y_path)
    with open(features_path, "r") as f:
        feature_names = json.load(f)

    return X, y, feature_names


def load_dataset(csv_path):
    """
    Build X, y, ids from a CSV with columns:
        patient_id, Class, image_path
    Maintains a canonical feature order captured from the first image.
    Later rows that are missing a feature key will be filled with 0.0 and logged.
    """
    df = pd.read_csv(csv_path)
    required_cols = {"patient_id", "Class", "image_path"}
    missing = required_cols - set(df.columns)
    if missing:
        raise FileNotFoundError(f"CSV is missing columns: {sorted(missing)}")

    X, y, ids = [], [], []
    feature_names = None

    for idx, row in df.iterrows():
        try:
            label = _normalize_label(row["Class"])
        except Exception as e:
            print(f"⚠️ Row {idx}: skipping due to bad label ({e})")
            continue

        img_path = str(row["image_path"])
        if not os.path.exists(img_path):
            print(f"⚠️ Row {idx}: missing image: {img_path}")
            continue

        feats = extract_image_features(img_path)

        # Initialize canonical feature order from the first successful row
        if feature_names is None:
            feature_names = list(feats.keys())

        # Fill vector strictly following canonical order; warn if a key is missing
        vec = []
        for k in feature_names:
            if k in feats:
                vec.append(feats[k])
            else:
                print(f"⚠️ Row {idx}: feature '{k}' missing in extraction; filling 0.0")
                vec.append(0.0)

        X.append(vec)
        y.append(label)
        ids.append(row["patient_id"])

    if feature_names is None or len(X) == 0:
        raise RuntimeError("No usable rows found in CSV. Check paths and labels.")

    # Consistent dtypes
    X = np.asarray(X, dtype=np.float32)
    y = np.asarray(y, dtype=np.int32)

    return X, y, ids, feature_names


def run():
    print("🔄 Loading training set from data/train.csv ...")
    X_train, y_train, ids_train, feat_names = load_dataset("data/train.csv")
    np.save(os.path.join(OUT_DIR, "X_train.npy"), X_train)
    np.save(os.path.join(OUT_DIR, "y_train.npy"), y_train)
    np.save(os.path.join(OUT_DIR, "ids_train.npy"), np.asarray(ids_train))

    print("🔄 Loading test set from data/test.csv ...")
    X_test, y_test, ids_test, _ = load_dataset("data/test.csv")
    np.save(os.path.join(OUT_DIR, "X_test.npy"), X_test)
    np.save(os.path.join(OUT_DIR, "y_test.npy"), y_test)
    np.save(os.path.join(OUT_DIR, "ids_test.npy"), np.asarray(ids_test))

    # Save canonical feature order for the whole pipeline
    with open(os.path.join(OUT_DIR, "feature_names.json"), "w") as f:
        json.dump(feat_names, f, indent=2)

    # Quick sanity prints
    b_tr = float((y_train == 0).mean())
    b_te = float((y_test == 0).mean())
    print("✅ Preprocessing complete.")
    print(f"Train: X={X_train.shape}, Benign share={b_tr:.3f}")
    print(f"Test : X={X_test.shape}, Benign share={b_te:.3f}")
    print(f"Features ({len(feat_names)}): {feat_names[:12]}{' ...' if len(feat_names)>12 else ''}")


if __name__ == "__main__":
    run()
