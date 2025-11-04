<?php
$python = '"C:\xampp\htdocs\CUR_TOOL\WOA-TOOL\.venv\Scripts\python.exe"';
$workdir = 'C:\xampp\htdocs\CUR_TOOL\WOA-TOOL';

if (!function_exists('build_predict_cmd')) {
function build_predict_cmd($image) {
    global $python, $workdir;
    $ewoa_model = "$workdir/models/model_final_ewoa.json";
    if (!file_exists($ewoa_model)) {
        // Fallbacks
        $ewoa_model = file_exists("$workdir/models/model_ewoa.json") ? "$workdir/models/model_ewoa.json" : "$workdir/models/model.json";
    }
    return "PYTHONPATH=$workdir $python -m woa_tool.cli predict --model $ewoa_model --image $image";
}
}

// Default parameters (you can expand later)
$defaults = [
    "runs" => 30,
    "iters" => 100,
    "pop" => 30,
    "dim" => 30,
    "a_strategy" => "sin",
];

// === Model paths ===
$models = [
    "woa"  => "$workdir/models/model_woa.json",
    "ewoa" => "$workdir/models/model_final_ewoa.json",
    "default" => "$workdir/models/model_final_ewoa.json"
];

// Return the config as array
return [
    "python_path" => $python,
    "workdir" => $workdir,
    "defaults" => $defaults,
    "models" => $models,
    // Set this to your ground-truth CSV file for comparison page
    // Example: C:\\xampp\\htdocs\\CUR_TOOL\\WOA-TOOL\\data\\ground_truth.csv
    "csv_path" => $workdir . '/data/ground_truth.csv'
];
