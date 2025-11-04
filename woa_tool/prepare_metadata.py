import os
import glob
import shutil
import pandas as pd
from sklearn.model_selection import train_test_split

# === Paths ===
data_dir = "data"
jpeg_dir = os.path.join(data_dir, "images")   # you renamed 'jpeg' → 'images'
metadata_file = os.path.join(data_dir, "metadata.csv")
train_file = os.path.join(data_dir, "train.csv")
test_file = os.path.join(data_dir, "test.csv")
test_images_dir = os.path.join(data_dir, "test_images")
os.makedirs(test_images_dir, exist_ok=True)

# === Step 1: Read all description CSVs ===
csv_files = glob.glob(os.path.join(data_dir, "*description*.csv"))
df_list = [pd.read_csv(f) for f in csv_files]
df = pd.concat(df_list, ignore_index=True)

# === Step 2: Keep only benign/malignant ===
df = df[df["pathology"].isin(["BENIGN", "MALIGNANT"])].copy()
df["Class"] = df["pathology"].map({"BENIGN": "B", "MALIGNANT": "M"})

# === Step 3: Extract UID from CSV path ===
def extract_uid(path: str):
    # Find all UID-like parts
    parts = [p for p in path.split("/") if p.startswith("1.3.6.1.4.1.")]
    if not parts:
        return None
    return parts[-1] 

df["UID"] = df["image file path"].apply(extract_uid)

# === Step 4: Build UID → JPEG path map ===
uid_to_jpeg = {}

for root, _, files in os.walk(jpeg_dir):
    jpgs = [f for f in files if f.lower().endswith((".jpg", ".jpeg", ".png"))]
    if not jpgs:
        continue
    # 🔑 FIX: look for UID in the full folder path
    parts = root.split(os.sep)
    uid = next((p for p in parts if p.startswith("1.3.6.1.4.1.")), None)
    if uid:
        jpgs.sort()
        uid_to_jpeg[uid] = os.path.join(root, jpgs[0])

print(f"🔍 Found {len(uid_to_jpeg)} UID folders with JPEGs")

# === Step 5: Match CSV → JPEGs ===
df["image_path"] = df["UID"].map(uid_to_jpeg)

missing = df[df["image_path"].isna()]
if not missing.empty:
    print(f"⚠️ Missing {len(missing)} images (no JPEG match)")
    print(missing[["patient_id", "UID"]].head(20))  # show sample missing

df = df.dropna(subset=["image_path"])

# === Step 6: Save metadata ===
df_out = df[["patient_id", "Class", "image_path"]].reset_index(drop=True)
df_out.to_csv(metadata_file, index=False)
print(f"✅ Metadata saved: {metadata_file} ({len(df_out)} rows)")

if df_out.empty:
    raise SystemExit("❌ No matching images found. Check UID extraction vs folder names.")

# === Step 7: Train/Test split ===
train_df, test_df = train_test_split(
    df_out, test_size=0.2, stratify=df_out["Class"], random_state=42
)
train_df.to_csv(train_file, index=False)
test_df.to_csv(test_file, index=False)
print(f"✅ Training set: {len(train_df)} rows → {train_file}")
print(f"✅ Test set: {len(test_df)} rows → {test_file}")

# === Step 8: Copy test images into test_images/ ===
copied = 0
for _, row in test_df.iterrows():
    src = row["image_path"]
    dst = os.path.join(test_images_dir, os.path.basename(src))
    if os.path.exists(src):
        shutil.copy(src, dst)
        copied += 1

print(f"📂 Copied {copied} test images into {test_images_dir}")
