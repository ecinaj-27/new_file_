# woa_tool/cli.py
import argparse
import sys
import json
import os

import woa_tool.preprocess as preprocess
import woa_tool.train as train
import woa_tool.predict as predict


def main():
    parser = argparse.ArgumentParser(
        prog="woa-tool",
        description="WOA vs EWOA tool (with OBL and adaptive parameters)"
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    # --------------------------
    # preprocess
    # --------------------------
    subparsers.add_parser(
        "preprocess",
        help="Extract image features and save processed numpy arrays"
    )

    # --------------------------
    # train
    # --------------------------
    train_parser = subparsers.add_parser("train", help="Train model on processed features")
    train_parser.add_argument("--processed", required=True, help="Path to processed directory (e.g., data/processed)")
    train_parser.add_argument("--algo", choices=["woa", "ewoa"], default="ewoa", help="Algorithm to use")
    train_parser.add_argument("--iters", type=int, default=100, help="Number of iterations")
    train_parser.add_argument("--pop", type=int, default=30, help="Population size")
    train_parser.add_argument("--out", default="models/model.json", help="Output model path")
    train_parser.add_argument("--folds", type=int, default=5, help="Cross-validation folds")
    train_parser.add_argument("--a-strategy", choices=["linear", "sin", "cos", "log", "tan", "square"], default="linear")
    train_parser.add_argument("--obl-freq", type=int, default=0, help="OBL frequency (0 = disabled)")
    train_parser.add_argument("--obl-rate", type=float, default=0.0, help="OBL rate (0.0 = disabled)")

    # --------------------------
    # predict
    # --------------------------
    pred_parser = subparsers.add_parser("predict", help="Predict class for a new image")
    pred_parser.add_argument("--model", required=True, help="Path to trained model JSON")
    pred_parser.add_argument("--image", required=True, help="Path to image file")
    pred_parser.add_argument(
        "--tau-override", type=float, default=None,
        help="Override τ at inference (e.g., 1.0014). If unset, use τ from model JSON (or ENV/sidecar if enabled)."
    )
    pred_parser.add_argument(
        "--out-json", default=None,
        help="Optional path to save the prediction as JSON (prints to stdout if omitted)."
    )
    pred_parser.add_argument(
        "--no-pretty", action="store_true",
        help="If set, do not pretty-print JSON to stdout."
    )

    # --------------------------
    # set-tau (persist τ into model)
    # --------------------------
    settau_parser = subparsers.add_parser("set-tau", help="Persist τ into the model JSON")
    settau_parser.add_argument("--model", required=True, help="Path to trained model JSON")
    settau_parser.add_argument("--tau", required=True, type=float, help="τ to write")

    # --------------------------
    # Dispatch
    # --------------------------
    args = parser.parse_args()

    if args.command == "preprocess":
        preprocess.run()
        return 0

    if args.command == "train":
        train.train(
            processed_dir=args.processed,
            algo=args.algo,
            iters=args.iters,
            pop=args.pop,
            out=args.out,
            folds=args.folds,
            a_strategy=args.a_strategy,
            obl_freq=args.obl_freq,
            obl_rate=args.obl_rate,
        )
        return 0

    if args.command == "predict":
        # Basic existence checks to give clearer errors
        if not os.path.isfile(args.model):
            print(f"❌ Model file not found: {args.model}", file=sys.stderr)
            return 2
        if not os.path.isfile(args.image):
            print(f"❌ Image file not found: {args.image}", file=sys.stderr)
            return 2

        res = predict.predict(args.model, args.image, tau_override=args.tau_override)

        if args.out_json:
            with open(args.out_json, "w") as f:
                json.dump(res, f, indent=2)
            print(f"✅ Saved prediction to {args.out_json}")
            return 0

        if args.no_pretty:
            print(json.dumps(res, separators=(",", ":")))
        else:
            print(json.dumps(res, indent=2))
        return 0

    if args.command == "set-tau":
        if not os.path.isfile(args.model):
            print(f"❌ Model file not found: {args.model}", file=sys.stderr)
            return 2
        with open(args.model, "r") as f:
            cfg = json.load(f)
        cfg["tau"] = float(args.tau)
        with open(args.model, "w") as f:
            json.dump(cfg, f, indent=2)
        # Optional sidecar for human visibility (useful with editors/grep)
        with open(args.model + ".tau", "w") as f:
            f.write(str(args.tau) + "\n")
        print(f"✅ Persisted τ={args.tau:.4f} to {args.model}")
        return 0

    return 0


if __name__ == "__main__":
    sys.exit(main())
