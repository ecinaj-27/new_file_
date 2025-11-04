<?php
// === Load Configuration ===
$config = require __DIR__ . '/config.php';
// === File Upload Configuration ===
$upload_dir = __DIR__ . '/test_uploads';
// Create folder if not present
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Ensure writable (so PHP can move_uploaded_file)
if (!is_writable($upload_dir)) {
    // Try changing permissions - may fail depending on server setup
    @chmod($upload_dir, 0777);
}

// === Utility Helpers ===
function get_workdir()
{
    global $config;
    return $config['workdir'];
}


function build_predict_cmd($imagePath)
{
    global $config;
    $python = $config['python_path']; // already quoted in config
    $workdir = $config['workdir'];

    // Prefer final models: model_final_ewoa.json, then model.json, then legacy model_ewoa.json
    $pref1 = $config['workdir'] . '/models/model_final_ewoa.json';
    $pref2 = $config['workdir'] . '/models/model.json';
    $pref3 = $config['workdir'] . '/models/model_ewoa.json';
    $model_path = file_exists($pref1) ? $pref1 : (file_exists($pref2) ? $pref2 : $pref3);
    $model = escapeshellarg($model_path);
    $image = escapeshellarg($imagePath);
    // Windows-friendly: set PYTHONPATH via cmd /C and run the module
    $inner = sprintf('set "PYTHONPATH=%s" && %s -m woa_tool.cli predict --model %s --image %s',
        $workdir,
        $python,
        $model,
        $image
    );
    return 'cmd /C "' . $inner . '"';
}

// === Pretty Names (Keep this updated!) ===
$pretty_names = [
    // === TEXTURE FEATURES (GLCM) ===
    "glcm_ASM" => "GLCM Angular Second Moment (Homogeneity)",
    "glcm_contrast" => "GLCM Contrast (Texture Roughness)",
    "glcm_correlation" => "GLCM Correlation (Pixel Dependency)",
    "glcm_variance" => "GLCM Variance (Gray-Level Spread)",
    "glcm_IDM" => "GLCM Inverse Difference Moment (Uniformity)",
    "glcm_sum_avg" => "GLCM Sum Average", // Added based on zscores
    "glcm_sum_var" => "GLCM Sum Variance", // Added
    "glcm_sum_entropy" => "GLCM Sum Entropy (Complexity)",
    "glcm_entropy" => "GLCM Entropy (Randomness)",
    "glcm_diff_var" => "GLCM Difference Variance", // Added
    "glcm_diff_entropy" => "GLCM Difference Entropy", // Added
    "glcm_IMC1" => "GLCM Info Measure of Correlation 1",
    "glcm_IMC2" => "GLCM Info Measure of Correlation 2",
    "glcm_direction_var" => "GLCM Directional Variance", // Added

    // === HISTOGRAM INTENSITY FEATURES ===
    "hist_mean" => "Histogram Mean Intensity (μ)",
    "hist_std" => "Histogram Standard Deviation (σ)",
    "hist_skew" => "Histogram Skewness (Asymmetry)",
    "hist_kurtosis" => "Histogram Kurtosis (Peak Sharpness)",
    "hist_q25" => "Histogram 25th Percentile (Q1)",
    "hist_q50" => "Histogram Median (Q2)",
    "hist_q75" => "Histogram 75th Percentile (Q3)",
    "density_index" => "Tissue Density Index",

    // === EDGE AND GRADIENT FEATURES ===
    "edge_sobel_mean" => "Mean Edge Strength (Sobel)",
    "edge_sobel_std" => "Edge Strength Variability (Sobel)",
    "edge_ratio" => "Edge Ratio", // Added
    "grad_coherence_mean" => "Gradient Coherence Mean",
    "grad_coherence_std" => "Gradient Coherence Std",
    "sharp_lap_var" => "Laplacian Variance (Sharpness)", // Added

    // === SHAPE & ASYMMETRY FEATURES ===
    "shape_area" => "Shape Area (pixels²)",
    "shape_perimeter" => "Shape Perimeter (pixels)",
    "shape_circularity" => "Shape Circularity",
    "shape_eccentricity" => "Shape Eccentricity (Elongation)",
    "shape_solidity" => "Shape Solidity",
    "shape_extent" => "Shape Extent Ratio",
    "shape_norm_area" => "Normalized Shape Area", // Added
    "asym_absdiff_mean" => "Asymmetry Abs Diff Mean",
    "asym_absdiff_std" => "Asymmetry Abs Diff Std",
    "asym_mean_diff" => "Asymmetry Mean Difference",

    // === MASS & BLOB CHARACTERISTICS ===
    "blob_count" => "Detected Blob Count",
    "blob_density" => "Blob Density", // Added
    "blob_radius_mean" => "Average Blob Radius",
    "blob_radius_std" => "Blob Radius Variability",

    // === SPICULATION FEATURES ===
    "spic_edge_density" => "Spiculation Edge Density",
    "spic_edge_ring_ratio" => "Spiculation Ring Ratio",
    "spic_orient_dispersion" => "Spiculation Orientation Dispersion", // Added

    // === ABNORMALITY SCORES (Specific to your output) ===
    "texture_disorder" => "Texture Disorder Score",
    "shape_irregularity" => "Shape Irregularity Score",
    "spiculation_index" => "Spiculation Index Score",
    // "density_index" => "Density Index Score", // Already defined above

    // === Additional Synthesized Features (Optional - keep if needed) ===
    "texture_variance" => "Texture Variance (Derived)",
    "asymmetry_index" => "Global Asymmetry Index",
    "compactness" => "Lesion Compactness",
    "roughness" => "Surface Roughness Estimate",
];

// === Standard PHP Setup ===
ob_start();
if (!empty($_POST['ajax'])) {
    ini_set('display_errors', 0);
    error_reporting(E_ERROR | E_PARSE);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

$result = null;
$error = null;
$uploadedImageWebPath = null;
$isDebug = isset($_GET['debug']);
$debug_pack = null; // Initialize debug pack

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {

    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload error code: " . $_FILES['image']['error'];
    } elseif ($_FILES['image']['size'] == 0) {
        $error = "Uploaded file is empty.";
    } elseif (!is_uploaded_file($_FILES['image']['tmp_name'])) {
        $error = "Possible file upload attack.";
    } else {
        $fileName = uniqid('img_', true) . '-' . preg_replace('/[^A-Za-z0-9\.\-\_]/', '', basename($_FILES['image']['name']));
        $targetPath = $upload_dir . '/' . $fileName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            $uploadedImageWebPath = 'test_uploads/' . basename($targetPath);

            // --- Real Prediction Logic ---
            if (empty($_POST['mock'])) {
                $cmd = build_predict_cmd($targetPath);
                $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],];
                $proc = proc_open($cmd, $desc, $pipes, get_workdir());

                if (is_resource($proc)) {
                    fclose($pipes[0]);
                    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
                    $code = proc_close($proc);

                    $decoded = json_decode($stdout, true, 512, JSON_BIGINT_AS_STRING);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        // --- Normalize abnormality_type (Seems okay based on your JSON example) ---
                         if (!isset($decoded['abnormality_type'])) {
                             if (isset($decoded['abnormality']['type'])) { $decoded['abnormality_type'] = $decoded['abnormality']['type']; }
                             elseif (isset($decoded['abnormality'])) { $decoded['abnormality_type'] = is_array($decoded['abnormality']) ? ($decoded['abnormality']['label'] ?? null) : $decoded['abnormality']; }
                             elseif (isset($decoded['lesion_type'])) { $decoded['abnormality_type'] = $decoded['lesion_type']; }
                       }
                        // --- end normalization ---
                        $result = $decoded;
                    } else {
                        $jsonErrorMsg = json_last_error_msg();
                        $error = "Model did not return valid JSON (Error: $jsonErrorMsg).";
                        if (!empty($stderr) || $code !== 0 || !empty($stdout)) {
                         $error .= "<br>Exit Code: " . htmlspecialchars($code);
                         if (!empty($stderr)) { $error .= "<br>Stderr: <pre>" . htmlspecialchars($stderr) . "</pre>"; }
                             if (!empty($stdout) && json_last_error() !== JSON_ERROR_NONE) { $error .= "<br>Raw Stdout: <pre>" . htmlspecialchars($stdout) . "</pre>"; }
                        }
                    }

                    if ($isDebug) {
                        $model_path = ($config['models']['ewoa'] ?? ($config['workdir'] . '/models/model_final_ewoa.json'));
                        $debug_pack = [ /* ... (keep your debug pack fields) ... */ ];
                    }

                } else {
                    $error = "proc_open failed — shell execution issue? Check PHP configuration (e.g., disable_functions), server permissions, or if the Python path is correct.";
                }
            }
            // --- End Real Prediction ---

        } else {
            $error = "Failed to move uploaded file. Check permissions for '$upload_dir'. Error code: " . ($_FILES['image']['error'] ?? 'unknown');
        }
    }
}

// === AJAX Response (Real or Error) ===
if (!empty($_POST['ajax'])) {
    $noise = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
    'ok'    => (bool) $result && !$error,
    'result' => $result,
    'image'  => $uploadedImageWebPath ?: null,
    'error'  => $error,
    'noise'  => $isDebug ? ($noise ?: null) : null,
    'debug'  => $isDebug ? ($debug_pack ?? null) : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    exit;
}
// === END AJAX Handling ===

$jsonData = $result ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null';
$jsonPrettyNames = json_encode($pretty_names); // Pass pretty names to JS
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1" />
    <title>WOA & EWOA Breast Cancer Feature Detection</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐳</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=27"> <!-- Increment version -->
    
    
    <script src="https://cdn.jsdelivr.net/npm/tiff.js@1.0.0/tiff.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        window.__PREDICT__ = <?php echo $jsonData; ?>;
        window.__UPLOADED_IMAGE__ = <?php echo json_encode($uploadedImageWebPath ?: null); ?>;
        window.__PRETTY_NAMES__ = <?php echo $jsonPrettyNames; ?>; // Make names available to JS
    </script>
</head>
<body>
    <header class="main-header">
        <div class="header-inner">
            <div class="header-left"> <div class="header-logo">🐋</div> <div class="header-title"> <h1>WOA: <span>Balancing Exploration–Exploitation</span></h1> <p>for Breast Cancer Feature Detection</p> </div> </div>
            <nav class="header-nav">
                <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">Feature Detection</a>
                <a href="benchmark.php" class="<?= basename($_SERVER['PHP_SELF']) == 'benchmark_backend.php' ? 'active' : '' ?>">Benchmark Functions</a>
                <a href="comparison.php" class="<?= basename($_SERVER['PHP_SELF']) == 'comparison.php' ? 'active' : '' ?>">Comparison</a>
            </nav>
        </div>
    </header>

    <div id="aurora-background"></div>

    <div class="main-container">
        <header class="header">
             <h1> <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C10.14 2 8.5 3.65 8.5 5.5C8.5 6.4 8.89 7.2 9.5 7.82C7.03 8.35 5.3 10.13 5.3 12.39C5.3 13.53 5.79 14.58 6.6 15.35C5.59 16.32 5 17.58 5 19C5 21.21 6.79 23 9 23C10.86 23 12.5 21.35 12.5 19.5C12.5 18.6 12.11 17.8 11.5 17.18C13.97 16.65 15.7 14.87 15.7 12.61C15.7 11.47 15.21 10.42 14.4 9.65C15.41 8.68 16 7.42 16 6C16 3.79 14.21 2 12 2M12 4C13.1 4 14 4.9 14 6C14 7.03 13.2 7.9 12.18 7.97C12.12 7.99 12.06 8 12 8C10.9 8 10 7.1 10 6C10 4.9 10.9 4 12 4M9 21C7.9 21 7 20.1 7 19C7 17.97 7.8 17.1 8.82 17.03C8.88 17.01 8.94 17 9 17C10.1 17 11 17.9 11 19C11 20.1 10.1 21 9 21" /></svg> EWOA Breast Cancer Feature Detection </h1>
             <div class="quick-guide"> <h3> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg> Quick Start Guide </h3> <ul> <li><strong>Step 1:</strong> Upload mammogram (<code>.png</code>, <code>.jpg</code>, <code>.tif</code>).</li> <li><strong>Step 2:</strong> Click <strong>Run Prediction</strong>.</li> <li><strong>Step 3:</strong> View results.</li> </ul> </div>
        </header>

        <div class="left-column">
            <div class="step-card">
                <div class="step-header">
                    <div class="step-header-left"> <div class="step-number">1</div> <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg> Upload Image</h2> </div>
                    <span class="tooltip-icon">i<span class="tooltip-content">Accepted formats: .tif, .tiff, .png, .jpg, .jpeg. Size limit depends on server config.</span></span>
                </div>
                <form id="image-upload-form" method="post" enctype="multipart/form-data">
                    <div id="image-preview-wrapper" style="display: none;">
                        <button type="button" class="maximize-btn" title="Maximize Image" aria-label="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"> <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                        <canvas></canvas>
                        <p id="image-filename" class="file-meta" style="display:none;"></p>
                    </div>
                    <div class="upload-area" id="upload-area">
                        <input type="file" name="image" id="file-input" accept=".tif,.tiff,.png,.jpg,.jpeg" required>
                        <svg class="upload-area__icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                        <p class="upload-area__text">Drag & Drop image file or <span>browse</span> to upload.</p>
                    </div>
                </form>
            </div>
            <div class="step-card text-center">
                <div class="step-header"> <div class="step-header-left"> <div class="step-number">2</div> <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75L16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z" /></svg> Run Analysis</h2> </div> </div>
                <p style="color:var(--text-dark); margin-bottom: 2rem;">Once image selected, button active.</p>
                <button class="btn" type="submit" id="submit-btn" disabled form="image-upload-form"> <span id="btn-text">Run Prediction</span> <div class="spinner" id="spinner" style="display:none;"></div> </button>
                <button class="btn btn-secondary" type="button" id="clear-btn" style="margin-top:0; margin-left:.75rem; display: none;">↺ Reset</button>
            </div>
            <div id="error-container"></div>
        </div>

        <div class="right-column">

            <div id="results-placeholder" style="display: block;"> <div class="step-card placeholder-card single-placeholder"> <div class="step-header"> <div class="step-header-left"> <div class="step-number" style="background-color: var(--text-dark); box-shadow: none;"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 20px; height: 20px; color: white;"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg> </div> <h2>Results Preview</h2> </div> </div> <div class="placeholder-content"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 1.085-1.085-1.085m1.085 1.085L5.25 16.5m7.5 0l-1 1.085m0 0l-1.085-1.085m1.085 1.085L18.75 16.5m-7.5 2.25h.008v.008H11.25v-.008zM12 3.75h.008v.008H12V3.75z" /></svg> <p>Analysis results will be displayed here after running the prediction.</p> </div> </div> </div>

            <div class="skeleton-container animate-slide-up" id="skeleton-loader" style="display: none;"> <div class="step-card loader-card"> <div class="loader-inner"> <div class="scan-loader"> <span></span><span></span><span></span><span></span> </div> <p class="loader-caption">Analyzing mammogram... please wait</p> </div> </div> </div>

            <div class="results-container animate-slide-up" id="results-container" style="display:none;">
                <div class="step-card"> <div class="step-header">
                        <div class="step-header-left"> <div class="step-number">3</div> <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> View Results</h2> </div>
                        <div class="header-buttons">
                            <button type="button" class="btn btn-print" id="print-btn" title="Print Report"> <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="20" height="20" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 9V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v4" /> <path stroke-linecap="round" stroke-linejoin="round" d="M6 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-1" /> <path stroke-linecap="round" stroke-linejoin="round" d="M7 14h10v7H7z" /></svg> <span>Print Results</span> </button>
                            <button type="button" class="btn btn-csv" id="csv-btn" title="Download CSV"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg> <span>Download CSV</span> </button>
                        </div>
                    </div>

                    <div class="results-grid" id="results-grid">

                        <!-- === UPDATED: Prediction Card with Visualizer === -->
                        <div class="step-card prediction-card animate-slide-up" id="prediction-card-content">
                            <div class="step-header">
                                <div class="step-header-left">
                                    <h2 style="padding-left:0;">Final Prediction <span class="pill pill-rule">Rule-based</span></h2>
                                </div>
                                <button type="button" class="maximize-card-btn" title="Maximize"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg> </button>
                            </div>
                            <div class="card-content prediction-content-layout"> <!-- Added class -->

                                <!-- Left side: Text & Details -->
                                <div class="prediction-details-section">
                                    <div class="prediction-text-wrapper">
                                        <span class="prediction-indicator"></span>
                                        <span style="font-size:3.5rem; font-weight:800;" data-field="final_prediction">—</span>
                                    </div>

                                    <div class="toggle-wrapper" style="text-align: right; margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap;">
                                        <button type="button" class="btn-toggle-values" data-state="pct">
                                            <span>Show Raw Values</span>
                                        </button>
                                        <button type="button" class="btn-toggle-details">
                                            <span>Show Decision Logic</span>
                                            <svg class="toggle-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/></svg>
                                        </button>
                                    </div>

                                    <div class="decision-details collapsible-content">
                                        <table class="data-table" style="margin-top:.25rem;">
                                            <tr>
                                                <th class="dist-toggle-header" data-pct-text="d<sub>B</sub> (Rel. %)" data-raw-text="d<sub>B</sub> (Raw)">
                                                    <span class="header-text">d<sub>B</sub> (Rel. %)</span> <span class="tooltip-icon">?<span class="tooltip-content">Relative distance to Benign centroid.</span></span>
                                                </th>
                                                <td data-field="distance_to_benign" data-raw-val="—" data-pct-val="—">—</td>
                                            </tr>
                                            <tr>
                                                <th class="dist-toggle-header" data-pct-text="d<sub>M</sub> (Rel. %)" data-raw-text="d<sub>M</sub> (Raw)">
                                                    <span class="header-text">d<sub>M</sub> (Rel. %)</span> <span class="tooltip-icon">?<span class="tooltip-content">Relative distance to Malignant centroid.</span></span>
                                                </th>
                                                <td data-field="distance_to_malignant" data-raw-val="—" data-pct-val="—">—</td>
                                            </tr>
                                            <tr>
                                                <th class="dist-toggle-header" data-pct-text="τ (%)" data-raw-text="τ (Raw)">
                                                    <span class="header-text">τ (%)</span> <span class="tooltip-icon">?<span class="tooltip-content">Decision threshold ratio as percentage. Raw value is the direct ratio.</span></span>
                                                </th>
                                                <td data-field="tau" data-raw-val="—" data-pct-val="—">—</td>
                                            </tr>
                                            <!-- ADD these rows inside the same <table class="data-table"> in the decision-details -->
                                            <tr class="ratio-row">
                                              <th class="dist-toggle-header"
                                                  data-pct-text="d<sub>M</sub>/d<sub>B</sub> (%)"
                                                  data-raw-text="d<sub>M</sub>/d<sub>B</sub> (Raw)">
                                                <span class="header-text">d<sub>M</sub>/d<sub>B</sub> (%)</span>
                                                <span class="tooltip-icon">?<span class="tooltip-content">
                                                  Distance ratio r = d<sub>M</sub> / d<sub>B</sub>. Lower favors Malignant.
                                                </span></span>
                                              </th>
                                              <td data-field="distance_ratio" data-raw-val="—" data-pct-val="—">—</td>
                                            </tr>
                                            <tr>
                                              <th class="dist-toggle-header"
                                                  data-pct-text="Malignant Margin (% over boundary)"
                                                  data-raw-text="Malignant Margin (×)">
                                                <span class="header-text">Malignant Margin (% over boundary)</span>
                                                <span class="tooltip-icon">?<span class="tooltip-content">
                                                  τ / r. &gt; 1 means inside malignant side by that factor (or %).
                                                </span></span>
                                              </th>
                                              <td data-field="mal_margin" data-raw-val="—" data-pct-val="—">—</td>
                                            </tr>
                                            <tr>
                                              <th class="dist-toggle-header"
                                                  data-pct-text="Benign Margin (% over boundary)"
                                                  data-raw-text="Benign Margin (×)">
                                                <span class="header-text">Benign Margin (% over boundary)</span>
                                                <span class="tooltip-icon">?<span class="tooltip-content">
                                                  r / τ. &gt; 1 means inside benign side by that factor (or %).
                                                </span></span>
                                              </th>
                                              <td data-field="ben_margin" data-raw-val="—" data-pct-val="—">—</td>
                                            </tr>
                                            <tr>
                                              <th>Decision Verdict
                                                <span class="tooltip-icon">?<span class="tooltip-content">
                                                  Human-readable check of “Malignant if dM ≤ τ·dB”, with numbers.
                                                </span></span>
                                              </th>
                                              <td data-field="decision_verdict">—</td>
                                            </tr>
                                            <tr> <th>Rule <span class="tooltip-icon">?<span class="tooltip-content">The rule used for the final decision based on raw values.</span></span></th> <td data-field="ratio_decision">—</td> </tr>
                                        </table>
                                        <p class="file-meta" id="decision-note" style="margin-top:.5rem; text-align: right;"> {/* JS fills this */} </p>
                                    </div>
                                </div>

                                <!-- Right side: Visualizer -->
                                <div class="prediction-visualizer-section">
                                    <div class="final-vis" id="final-prediction-visualizer">
                                        <canvas id="prediction-gauge-chart"></canvas>
                                        <div class="ring-label">
                                            <span class="ring-main"></span>
                                            <span class="ring-sub">Confidence</span>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <!-- === END Prediction Card === -->

                        <div class="step-card animate-slide-up" id="probability-card-content" style="animation-delay:.1s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Confidence by Class</h2> </div>
                                <span class="tooltip-icon">i<span class="tooltip-content">Model confidence per class. This visualization supports the final prediction but the decision uses the ratio rule (τ).</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content"> <div id="probability-chart-container"><canvas id="probability-chart"></canvas></div> </div>
                        </div>

                        <div class="step-card animate-slide-up" id="background-card-content" style="animation-delay:.15s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Background Tissue Density</h2> </div> <!-- Updated Title -->
                                <span class="tooltip-icon">i<span class="tooltip-content">Inferred BI-RADS density category based on image features.</span></span> <!-- Updated Tooltip -->
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <!-- === NEW Card Content Structure === -->
                            <div class="card-content">
                                <div class="flex items-center space-x-4 mb-3">
                                    <span class="birads-badge" data-field="background_tissue_code_badge">?</span>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-500">BI-RADS Code</p>
                                        <p class="text-lg font-semibold" data-field="background_tissue_code">—</p>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-500">Description</p>
                                        <p class="text-lg font-semibold" data-field="background_tissue_text">—</p>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-2" data-field="background_tissue_explain">—</p>
                            </div>
                            <!-- === END NEW Card Content Structure === -->
                        </div>

                        <div class="step-card animate-slide-up" id="explanation-card-content" style="animation-delay:.20s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Explanations</h2> </div>
                                <span class="tooltip-icon">i<span class="tooltip-content">AI-generated explanations for the prediction.</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content" id="explain-root">
                                <!-- Content generated by renderExplanations JS -->
                            </div>
                        </div>

                        <div class="step-card animate-slide-up" id="abnormality-card-content" style="animation-delay:.25s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Abnormality Scores</h2> </div> <span class="tooltip-icon">i<span class="tooltip-content">Calculated scores for different abnormality characteristics.</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content">
                                <div class="abnormality-chart-wrapper"> <canvas id="abnormality-chart"></canvas> </div>
                            </div>
                        </div>

                        <!-- === MERGED: Top Features + Relative Chart === -->
                        <div class="step-card animate-slide-up" id="tfc-card-content" style="animation-delay:.30s;">
                          <div class="step-header">
                            <div class="step-header-left">
                              <h2>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.31h5.518a.562.562 0 01.31.95l-4.203 3.03a.563.563 0 00-.182.635l1.578 4.87a.562.562 0 01-.84.61l-4.72-3.47a.563.563 0 00-.652 0l-4.72 3.47a.562.562 0 01-.84-.61l1.578-4.87a.563.563 0 00-.182-.635L2.543 9.87a.562.562 0 01.31-.95h5.518a.563.563 0 00.475-.31L11.48 3.5z"/></svg>
                                Top Feature Contributors 
                              </h2>
                            </div>
                            <span class="tooltip-icon">i
                              <span class="tooltip-content">Features most influencing the decision (left) and their relative contribution (%) (right).</span>
                            </span>
                            <button type="button" class="maximize-card-btn" title="Maximize">
                              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg>
                            </button>
                          </div>

                          <div class="card-content">
                            <div class="tfc-layout-container">
                              <!-- Left: table -->
                              <div class="tfc-table-wrapper">
                                <div class="table-wrapper-scroll" id="tfc-table-scroll">
                                  <table class="data-table">
                                    <thead>
                                      <tr>
                                        <th>Feature</th>
                                        <th class="contrib-toggle-header" data-pct-text="Contribution (%)" data-raw-text="Contribution (Raw)">
                                          <span class="header-text">Contribution (%)</span>
                                        </th>
                                      </tr>
                                    </thead>
                                    <tbody data-field="top_feature_contributors"></tbody>
                                  </table>
                                </div>
                              </div>

                              <!-- Right: stacked bar chart -->
                              <div class="tfc-chart-wrapper" id="tfc-chart-wrapper">
                                <canvas id="tfc-stacked"></canvas>
                              </div>
                            </div>
                          </div>
                        </div>
                        <!-- === END: Merged Card === -->


                        <!-- === UPDATED: Z-Scores Card === -->
                        <div class="step-card animate-slide-up" id="zscores-card-content" style="animation-delay:.45s;">
                             <div class="step-header">
                                <div class="step-header-left">
                                      <h2>
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" /></svg>
                                        Feature Z-Scores
                                      </h2>
                                </div>
                                <span class="tooltip-icon">i<span class="tooltip-content">Standardized values (z-scores) for all radiomic features, shown as percentiles. Chart shows all features.</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                             </div>
                             <div class="card-content">
                                <!-- NEW: Z-Score Layout Container -->
                                <div class="zscore-layout-container">
                                    <!-- Left Side: Table -->
                                    <div class="zscore-table-wrapper">
                                        <div class="table-wrapper-scroll"> <!-- Note: max-height will be set by JS -->
                                             <table class="data-table">
                                                 <thead>
                                                     <tr>
                                                         <th>Feature</th>
                                                         <th class="zscore-toggle-header" data-pct-text="Percentile (%)" data-raw-text="Z-Score">
                                                              <span class="header-text">Percentile (%)</span>
                                                         </th>
                                                     </tr>
                                                 </thead>
                                                 <tbody data-field="zscores">    </tbody>
                                             </table>
                                        </div>
                                    </div>
                                    <!-- Right Side: Chart -->
                                    <div class="zscore-chart-wrapper"> <!-- Note: height will be set by JS -->
                                         <canvas id="zscore-chart"></canvas>
                                    </div>
                                </div>
                             </div>
                        </div>
                        <!-- === END: Z-Scores Card === -->


                    </div> </div>
            </div> <?php if ($result && !$isDebug): /* Keep this for raw JSON view if needed */ ?>
                        <?php endif; ?>
        </div> </div> <footer> <p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p> </footer>

    <div id="image-modal-overlay"></div>
    <div id="card-modal-overlay"> <div id="card-modal-content"> <button class="close-modal-btn">&times;</button> <h2 class="modal-title"></h2> <div class="modal-body"></div> </div> </div>


    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // === Element Refs ===
        const fileInput = document.getElementById('file-input');
        const submitBtn = document.getElementById('submit-btn');
        const clearBtn = document.getElementById('clear-btn');
        const form = document.getElementById('image-upload-form');
        const spinner = document.getElementById('spinner');
        const btnText = document.getElementById('btn-text');
        const skeletonLoader = document.getElementById('skeleton-loader');
        const uploadArea = document.getElementById('upload-area');
        const resultsContainer = document.getElementById('results-container');
        const resultsGrid = document.getElementById('results-grid');
        const imageModalOverlay = document.getElementById('image-modal-overlay');
        const cardModalOverlay = document.getElementById('card-modal-overlay');
        const cardModalContent = document.getElementById('card-modal-content');
        const cardModalTitle = cardModalContent.querySelector('.modal-title');
        const cardModalBody = cardModalContent.querySelector('.modal-body');
        const closeCardModalBtn = cardModalContent.querySelector('.close-modal-btn');
        const errorContainer = document.getElementById('error-container');
        const previewWrapper = document.getElementById('image-preview-wrapper');
        const resultsPlaceholder = document.getElementById('results-placeholder');

        // === State ===
        let activeCharts = {}; // Use object to store charts by ID
        let currentMaximizedChartId = null;
        const PRETTY_NAMES = window.__PRETTY_NAMES__ || {}; // Load pretty names

        // === Persisted state (localStorage) ===
        const STORAGE_KEY = 'woa_result_state_v3'; // Incremented version
        function loadState() { try { const r = localStorage.getItem(STORAGE_KEY); return r ? JSON.parse(r) : null; } catch (e) { return null; } }
        function saveState(p) { try { const pr = loadState() || {}; let n = { ...pr, ...p, savedAt: Date.now() }; let pl = JSON.stringify(n); if (pl.length > 4_500_000) { delete n.previewDataUrl; pl = JSON.stringify(n); } localStorage.setItem(STORAGE_KEY, pl); } catch (e) { console.warn('State save failed:', e); } }
        function clearState() { try { localStorage.removeItem(STORAGE_KEY); } catch (e) {} }

        // === Get Computed CSS Colors ===
        const computedStyles = getComputedStyle(document.documentElement);
        const chartColors = { 
            accentGlow: computedStyles.getPropertyValue('--accent-glow').trim(), 
            accentGlowTint: computedStyles.getPropertyValue('--accent-glow-tint').trim(), 
            accentSuccess: computedStyles.getPropertyValue('--accent-success').trim(), 
            accentWarning: computedStyles.getPropertyValue('--accent-warning').trim(), 
            textDark: computedStyles.getPropertyValue('--text-dark').trim(), 
            borderColor: computedStyles.getPropertyValue('--border-color').trim(), 
            bgDark: computedStyles.getPropertyValue('--bg-dark').trim() 
        };
        const PASTELS = ['rgba(99, 179, 237, 0.7)','rgba(132, 204, 145, 0.7)','rgba(250, 202, 154, 0.7)','rgba(196, 181, 253, 0.7)','rgba(252, 165, 165, 0.7)','rgba(153, 246, 228, 0.7)'];
        // Dedicated pastel colors for the probability charts
        const PASTEL_PROBS = {
            benign: 'rgba(144, 238, 144, 0.8)',      // light green
            malignant: 'rgba(255, 182, 193, 0.8)'    // light pink
        };

        // === Utility Functions ===
        function showError(m) { errorContainer.innerHTML = `<div class="step-card error-card animate-slide-up"><strong>Error:</strong> ${m}</div>`; }
        function renderToCanvas(f) { return new Promise((res, rej) => { const isTiff = f.type === 'image/tiff' || f.name.toLowerCase().endsWith('.tif') || f.name.toLowerCase().endsWith('.tiff'); const rdr = new FileReader(); if (isTiff) { rdr.onload = e => { try { Tiff.initialize({ TOTAL_MEMORY: 16777216 * 10 }); const tiff = new Tiff({ buffer: e.target.result }); res(tiff.toCanvas()); } catch (err) { rej(err); } }; rdr.onerror = rej; rdr.readAsArrayBuffer(f); } else { rdr.onload = e => { const img = new Image(); img.onload = () => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height; c.getContext('2d').drawImage(img, 0, 0); res(c); }; img.onerror = rej; img.src = e.target.result; }; rdr.onerror = rej; rdr.readAsDataURL(f); } }); }
        function scaleCanvasToFit(sC, mW, mH) { const w = sC.width, h = sC.height; const sc = Math.min(mW / w, mH / h, 1); const o = document.createElement('canvas'); o.width = Math.round(w * sc); o.height = Math.round(h * sc); o.getContext('2d').drawImage(sC, 0, 0, o.width, o.height); return o; }
        function displayCanvas(c, cE) { const eC = cE.querySelector('canvas'); if (eC) eC.remove(); cE.prepend(c); cE.style.display = 'flex'; }
        function handleFileSelect() { if (fileInput.files.length > 0) { const f = fileInput.files[0]; renderToCanvas(f).then(rC => { const mW = previewWrapper.clientWidth || 900; const mH = 400; const sc = scaleCanvasToFit(rC, mW, mH); previewWrapper.dataset.fullImage = rC.toDataURL(); displayCanvas(sc, previewWrapper); const nE = document.getElementById('image-filename'); if (nE) { nE.textContent = f.name; nE.style.display = 'block'; } submitBtn.disabled = false; clearBtn.style.display = 'inline-flex'; uploadArea.style.display = 'none'; }).catch(err => { console.error(err); showError('Could not read or render image.'); }); } }
        function closeCardModal() { cardModalOverlay.classList.remove('visible'); document.body.style.overflow = ''; cardModalBody.innerHTML = ''; currentMaximizedChartId = null; /* Destroy modal chart instance if exists */ }
        function escapeHTML(s) { return String(s ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;'); }

        // --- Normal CDF for mapping z-score -> percentile ---
        function normalCdf(z){
            // Abramowitz & Stegun 7.1.26 approximation
            const b1=0.319381530, b2=-0.356563782, b3=1.781477937, b4=-1.821255978, b5=1.330274429;
            const p=0.2316419; const t=1/(1+p*Math.abs(z));
            const poly=((((b5*t + b4)*t + b3)*t + b2)*t + b1)*t;
            const phi=Math.exp(-0.5*z*z)/Math.sqrt(2*Math.PI);
            const cdf=1 - phi*poly;
            return z>=0 ? cdf : 1-cdf;
        }

        // === Modal Display Logic ===
        function showContentInModal(title, contentHtml, chartId = null) {
            cardModalTitle.textContent = title;
            cardModalBody.innerHTML = contentHtml; // Inject the cloned content first
            cardModalOverlay.classList.add('visible');
            document.body.style.overflow = 'hidden';
            currentMaximizedChartId = chartId;

            requestAnimationFrame(() => { // Ensure DOM is updated
                if (chartId) {
                    const resultData = window.__PREDICT__?.result;
                    if (!resultData) { console.error("No result data for modal chart:", chartId); return; }

                    const canvasInModal = cardModalBody.querySelector(`#${chartId}`); // Find canvas INSIDE modal
                    if (!canvasInModal) { console.error(`Canvas #${chartId} not found in modal body.`); return; }

                    let container = canvasInModal.closest('.card-content > div') || canvasInModal.parentElement;
                    if (container && !container.style.height) { // Ensure container has height
                        if (chartId === 'probability-chart') container.style.height = '400px';
                        else if (chartId === 'abnormality-chart') container.style.height = '500px';
                        else if (chartId === 'tfc-stacked') container.style.height = '400px'; // Use new ID
                        else if (chartId === 'zscore-chart') container.style.height = '800px'; // Taller for modal
                        else container.style.height = '400px';
                    }

                    const ctx = canvasInModal.getContext('2d');
                    if (!ctx) { console.error(`Could not get 2D context for modal canvas #${chartId}.`); return; }

                    // Destroy old modal chart instance before creating new one
                    if (activeCharts['modal_' + chartId]) {
                        activeCharts['modal_' + chartId].destroy();
                    }

                    try {
                        let newChart;


                        if (chartId === 'probability-chart') {
                            const probs = resultData.probabilities || {};
                            const ben = probs['Benign'] ?? 0;
                            const mal = probs['Malignant'] ?? 0;
                            newChart = new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: ['Benign', 'Malignant'],
                                    datasets: [{
                                        label: 'Model Probability (%)',
                                        data: [ben * 100, mal * 100],
                                        backgroundColor: [PASTEL_PROBS.benign, PASTEL_PROBS.malignant],
                                        borderWidth: 0,
                                        borderRadius: 8
                                    }]
                                },
                                options: {
                                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                                    scales: {
                                        x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' }, grid: { color: 'rgba(0,0,0,0.08)' } },
                                        y: { grid: { display: false } }
                                    },
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: { callbacks: { label: ctx => `${ctx.parsed.x.toFixed(1)}%` } }
                                    },
                                    animation: { duration: 700 }
                                }
                            });
                        }
                        
                        else if (chartId === 'zscore-chart') { // MODAL Z-Score Chart
                             const zs=resultData.zscores||{};
                             const zData=Object.keys(zs).map(k=>{const z=Number(zs[k]); const p=Number.isFinite(z)?(normalCdf(z)*100):50; return{label:PRETTY_NAMES[k]||k, z:z, p:p };});
                             // --- CHANGE: Sort by percentile, highest first ---
                             zData.sort((a,b)=>b.p - a.p); // Sort by percentile, highest first
                             
                             const labels = zData.map(d=>d.label);
                             const data = zData.map(d=>d.p);
                             const colors = zData.map(d => d.p > 95 || d.p < 5 ? chartColors.accentWarning : chartColors.accentGlowTint);
                             
                             newChart = new Chart(ctx, {
                                 type: 'bar',
                                 data: { labels: labels, datasets: [{ label: 'Percentile (%)', data: data, backgroundColor: colors, borderWidth: 0, borderRadius: 4 }] },
                                 options: {
                                     indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                                     scales: { 
                                         x: { min: 0, max: 100, ticks: { callback: v => v + '%' }, grid: { color: chartColors.borderColor } },
                                         y: { ticks: { font: { size: 10 } } } // Slightly larger font for modal
                                     },
                                     plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `${ctx.parsed.x.toFixed(1)}%` } } }
                                 }
                             });
                        }

                        else if (chartId === 'abnormality-chart') {
                            const abnScores = resultData.abnormality_scores || {}; const abnValues = Object.values(abnScores);
                            newChart = new Chart(ctx, { type: 'bar', data: { labels: Object.keys(abnScores).map(k => PRETTY_NAMES[k] || k), datasets: [{ label: 'Score', data: abnValues, backgroundColor: abnValues.map((_, i) => PASTELS[i % PASTELS.length]), borderWidth: 0, borderRadius: 5 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true }, y: { ticks: { font: {size: 14} } } }, plugins: { legend: { display: false } } } });
                        } else if (chartId === 'tfc-stacked') { // Use new ID
                            const tfc = Array.isArray(resultData.top_feature_contributors) ? resultData.top_feature_contributors : []; const total = tfc.reduce((s, x) => s + (x?.[1] || 0), 0) || 1; const pct = tfc.map(([label, v]) => [(PRETTY_NAMES[label] || label), (v / total) * 100]);
                            const datasets = pct.map(([label, value], i) => ({ label, data: [value], backgroundColor: PASTELS[i % PASTELS.length], borderWidth: 0 }));
                            newChart = new Chart(ctx, { type: 'bar', data: { labels: ['Contribution'], datasets }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true, min: 0, max: 100, ticks: { callback: v => v + '%' } }, y: { stacked: true } }, plugins: { legend: { position: 'right', labels:{ font:{ size: 12 } } } } } });
                        }
                        if(newChart) {
                            activeCharts['modal_' + chartId] = newChart; // Store modal chart instance
                        }
                    } catch (chartError) {
                        console.error(`Error creating chart #${chartId} in modal:`, chartError);
                    }
                }
            });
        }

        // === Event Listeners ===
        uploadArea.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', handleFileSelect);
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => { uploadArea.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); }, false); });
        ['dragenter', 'dragover'].forEach(ev => { uploadArea.addEventListener(ev, () => uploadArea.classList.add('dragover'), false); });
        ['dragleave', 'drop'].forEach(ev => { uploadArea.addEventListener(ev, () => uploadArea.classList.remove('dragover'), false); });
        uploadArea.addEventListener('drop', e => { fileInput.files = e.dataTransfer.files; handleFileSelect(); });

        form.addEventListener('submit', async e => {
            e.preventDefault(); submitBtn.disabled = true; spinner.style.display = 'block'; btnText.textContent = 'Analyzing...'; skeletonLoader.style.display = 'block'; resultsContainer.style.display = 'none'; resultsPlaceholder.style.display = 'none'; errorContainer.innerHTML = '';
            Object.values(activeCharts).forEach(c => c.destroy()); activeCharts = {}; // Clear all charts
            try {
                const formData = new FormData(form); formData.set('ajax', '1'); formData.delete('mock');
                const response = await fetch(window.location.href, { method: 'POST', body: formData }); const contentType = response.headers.get('content-type') || '';
                if (!response.ok) { const text = await response.text(); throw new Error(`HTTP ${response.status}\n\n${text.slice(0, 2000)}`); }
                if (!contentType.includes('application/json')) { const text = await response.text(); if (text.includes("POST Content-Length")) { throw new Error("File too large."); } throw new Error(`Expected JSON, got HTML/text:\n\n${text.slice(0, 500)}...`); }
                const payload = await response.json(); console.log('AJAX payload:', payload);
                if (payload.ok && payload.result) {
                    window.__PREDICT__ = payload;
                    displayResults(payload.result);
                    saveState({ result: payload.result, imagePath: payload.image || null, filename: fileInput?.files?.[0]?.name || null });
                    resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else { throw new Error(payload.error || payload.noise || 'Backend error.'); }
            } catch (err) { console.error('Fetch Error:', err); showError(err?.message?.replace(/\n/g, '<br>') || 'Analysis error.'); }
            finally { skeletonLoader.style.display = 'none'; spinner.style.display = 'none'; btnText.textContent = 'Run Prediction'; submitBtn.disabled = false; }
        });

        clearBtn.addEventListener('click', () => { clearState(); fileInput.value=''; previewWrapper.style.display='none'; previewWrapper.removeAttribute('data-full-image'); const eC=previewWrapper.querySelector('canvas'); if(eC)eC.remove(); const nE=document.getElementById('image-filename'); if(nE)nE.style.display='none'; resultsContainer.style.display='none'; errorContainer.innerHTML=''; skeletonLoader.style.display='none'; resultsPlaceholder.style.display='block'; btnText.textContent='Run Prediction'; submitBtn.disabled=true; clearBtn.style.display='none'; uploadArea.style.display='block'; Object.values(activeCharts).forEach(c=>c.destroy()); activeCharts={}; window.__PREDICT__=null; window.scrollTo({top:0,behavior:'smooth'}); });
        document.body.addEventListener('click', e => { if (e.target.closest('.maximize-btn')) { const dU = document.getElementById('image-preview-wrapper')?.dataset?.fullImage; if (dU) showImageInModal(dU); } });
        function showImageInModal(dU) { const i=new Image(); i.src=dU; i.style.maxWidth='90vw'; i.style.maxHeight='90vh'; i.style.borderRadius='12px'; imageModalOverlay.innerHTML=''; imageModalOverlay.appendChild(i); imageModalOverlay.classList.add('visible'); }
        imageModalOverlay.addEventListener('click', e => { if (e.target === imageModalOverlay) imageModalOverlay.classList.remove('visible'); });
        closeCardModalBtn.addEventListener('click', closeCardModal);
        cardModalOverlay.addEventListener('click', e => { if (e.target === cardModalOverlay) closeCardModal(); });

        // === UPDATED displayResults Function ===
        function displayResults(resultData) {
            resultsContainer.style.display='block'; resultsPlaceholder.style.display='none';

            // --- Prediction Card ---
            const predEl = document.querySelector('#prediction-card-content [data-field="final_prediction"]');
            const indEl = document.querySelector('#prediction-card-content .prediction-indicator');
            const pred = resultData.final_prediction || '—';
            const predClass = pred.toLowerCase();
            const predColor = pred === 'Malignant' ? chartColors.accentWarning : chartColors.accentSuccess;
            const predBgColor = pred === 'Malignant' ? chartColors.accentWarning : chartColors.accentSuccess; // Use same color for gauge

            if (predEl) { predEl.textContent = pred; predEl.style.color = predColor; const pC=predEl.closest('.prediction-card'); if(pC)pC.className=`step-card prediction-card animate-slide-up prediction-${predClass}`; }
            if (indEl) { const bSVG=`<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${chartColors.accentSuccess}"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`; const mSVG=`<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${chartColors.accentWarning}"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>`; indEl.innerHTML = pred === 'Malignant' ? mSVG : bSVG; }

            const probs = resultData.probabilities || {};
            const benProb = probs['Benign'] || 0;
            const malProb = probs['Malignant'] || 0;
            const confVal = Math.max(benProb, malProb);
            const probWinner = (benProb >= malProb) ? 'Benign' : 'Malignant';

            // --- Prediction Gauge Visualizer ---
            const gaugeCanvas = document.getElementById('prediction-gauge-chart');
            const ringLabelMain = document.querySelector('.final-vis .ring-main');
            if (gaugeCanvas && ringLabelMain) {
                const ctx = gaugeCanvas.getContext('2d');
                if (activeCharts['prediction-gauge']) activeCharts['prediction-gauge'].destroy();

                const gaugeValue = confVal * 100;
                const remainingValue = 100 - gaugeValue;

                activeCharts['prediction-gauge'] = new Chart(ctx, {
                    type: 'doughnut', data: { datasets: [{ data: [gaugeValue, remainingValue], backgroundColor: [ predBgColor, chartColors.bgDark ], borderWidth: 0, borderRadius: 5 }] },
                    options: { responsive: true, maintainAspectRatio: true, cutout: '75%', plugins: { legend: { display: false }, tooltip: { enabled: false } }, animation: { duration: 800, easing: 'easeOutQuart' }, elements: { arc: { roundedCorners: true, } } }
                });
                ringLabelMain.textContent = `${gaugeValue.toFixed(1)}%`; ringLabelMain.style.color = predColor;
            }

            // --- Distances, Tau, Rule ---
            const dB = Number(resultData.distance_to_benign);
            const dM = Number(resultData.distance_to_malignant);
            const tau = Number(resultData.tau);
            const ruleText = resultData.ratio_decision || '—';

            // Calculate Percentages for Distances/Tau
            const totalDist = dB + dM;
            let rawDB = '—', rawDM = '—', rawTau = '—';
            let pctBStr = '—', pctMStr = '—', pctTauStr = '—';
            if (Number.isFinite(dB) && Number.isFinite(dM) && totalDist > 0) { pctBStr = ((dB / totalDist) * 100).toFixed(1) + '%'; pctMStr = ((dM / totalDist) * 100).toFixed(1) + '%'; }
            if (Number.isFinite(dB)) rawDB = dB.toFixed(4);
            if (Number.isFinite(dM)) rawDM = dM.toFixed(4);
            if (Number.isFinite(tau)) { rawTau = tau.toFixed(4); pctTauStr = (tau * 100).toFixed(1) + '%'; }

            // Populate Distance/Tau Cells
            const dbCell = document.querySelector('[data-field="distance_to_benign"]');
            const dmCell = document.querySelector('[data-field="distance_to_malignant"]');
            const tauCell = document.querySelector('[data-field="tau"]');
            if(dbCell) { dbCell.dataset.rawVal = rawDB; dbCell.dataset.pctVal = pctBStr; dbCell.textContent = pctBStr; }
            if(dmCell) { dmCell.dataset.rawVal = rawDM; dmCell.dataset.pctVal = pctMStr; dmCell.textContent = pctMStr; }
            if(tauCell) { tauCell.dataset.rawVal = rawTau; tauCell.dataset.pctVal = pctTauStr; tauCell.textContent = pctTauStr; }
            document.querySelector('[data-field="ratio_decision"]').textContent = ruleText;

            // --- Decision Ratio & Margins Calculation & Population ---
            const ratioCell = document.querySelector('[data-field="distance_ratio"]');
            const malMarginCell = document.querySelector('[data-field="mal_margin"]');
            const benMarginCell = document.querySelector('[data-field="ben_margin"]');
            const verdictCell = document.querySelector('[data-field="decision_verdict"]');
            let r = NaN, malMargin = NaN, benMargin = NaN, verdictText = '—', inequality = '?', rhsRaw = '?', lhsRaw = '?';
            if (Number.isFinite(dB) && dB > 0 && Number.isFinite(dM) && Number.isFinite(tau) && tau > 0) {
                r = dM / dB; malMargin = tau / r; benMargin = r / tau;
                const isMalignant = (dM <= tau * dB);
                lhsRaw = dM.toFixed(4); rhsRaw = (tau * dB).toFixed(4); inequality = isMalignant ? '≤' : '>';
                verdictText = `Check (Malignant if dM ≤ τ·dB): dM=${lhsRaw} ${inequality} τ·dB=${rhsRaw} → ${isMalignant ? 'Malignant' : 'Benign'}`;
            }
            if (ratioCell) { const raw = Number.isFinite(r) ? r.toFixed(4) : '—'; const pct = Number.isFinite(r) ? (r * 100).toFixed(1) + '%' : '—'; ratioCell.dataset.rawVal = raw; ratioCell.dataset.pctVal = pct; ratioCell.textContent = pct; }
            if (malMarginCell) { const raw = Number.isFinite(malMargin) ? malMargin.toFixed(4) + '×' : '—'; const pct = Number.isFinite(malMargin) ? ((malMargin - 1) * 100).toFixed(1) + '%' : '—'; malMarginCell.dataset.rawVal = raw; malMarginCell.dataset.pctVal = pct; malMarginCell.innerHTML = `<strong>${pct}</strong>`; }
            if (benMarginCell) { const raw = Number.isFinite(benMargin) ? benMargin.toFixed(4) + '×' : '—'; const pct = Number.isFinite(benMargin) ? ((benMargin - 1) * 100).toFixed(1) + '%' : '—'; benMarginCell.dataset.rawVal = raw; benMarginCell.dataset.pctVal = pct; benMarginCell.innerHTML = `<strong>${pct}</strong>`; }
            if (verdictCell) { verdictCell.textContent = verdictText; }

            // Persist Ratio/Margins to resultData for CSV
            if (Number.isFinite(r)) resultData.distance_ratio = r.toFixed(6);
            if (Number.isFinite(malMargin)) { resultData.malignant_margin_x = malMargin.toFixed(6); resultData.malignant_margin_pct = ((malMargin - 1) * 100).toFixed(3) + '%'; }
            if (Number.isFinite(benMargin)) { resultData.benign_margin_x = benMargin.toFixed(6); resultData.benign_margin_pct = ((benMargin - 1) * 100).toFixed(3) + '%'; }
            if (verdictText && verdictText !== '—') resultData.decision_verdict = verdictText;

            // --- Reset Value Toggle Button & Headers ---
            const valueToggleBtn = document.querySelector('.btn-toggle-values');
            if (valueToggleBtn) { valueToggleBtn.dataset.state = 'pct'; valueToggleBtn.querySelector('span').textContent = 'Show Raw Values'; }
            document.querySelectorAll('.dist-toggle-header').forEach(header => { const ts = header.querySelector('.header-text'); if (ts) ts.innerHTML = header.dataset.pctText || ''; });

            // --- Update Decision Note ---
            const decisionNote = document.getElementById('decision-note');
            if (decisionNote) { const pd = (probWinner !== (resultData.final_prediction || '—')); decisionNote.textContent = `Decision Check: dM=${lhsRaw} ${inequality} τ·dB=${rhsRaw}${pd ? ' • Note: Probabilities disagree; ratio rule used.' : ''}`; }

            // --- Probability Chart ---
            const probCanvas = document.getElementById('probability-chart');
            if (probCanvas) {
                const ctx = probCanvas.getContext('2d');
                if (activeCharts['probability-chart']) activeCharts['probability-chart'].destroy();
                activeCharts['probability-chart'] = new Chart(ctx, {
                    type: 'bar', data: { labels: ['Benign', 'Malignant'], datasets: [{ label: 'Model Probability (%)', data: [benProb * 100, malProb * 100], backgroundColor: [PASTEL_PROBS.benign, PASTEL_PROBS.malignant], borderWidth: 0, borderRadius: 6 }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: true, scales: { x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' }, grid: { color: chartColors.borderColor } }, y: { grid: { display: false } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `${ctx.parsed.x.toFixed(1)}%` } } }, animation: { duration: 700 } }
                });
                const wrap = document.querySelector('#probability-card-content .card-content'); let noteEl = wrap.querySelector('.explain-note');
                if (!noteEl) { noteEl = document.createElement('div'); noteEl.className = 'explain-note file-meta'; noteEl.style.marginTop = '0.75rem'; noteEl.style.textAlign = 'center'; wrap.appendChild(noteEl); }
                noteEl.textContent = 'Note: These bars show model confidence. The final prediction uses the ratio test (τ).';
            }

            // --- Background Card ---
            const bg = resultData.background_tissue || {};
            const code = bg.code?.toUpperCase() ?? '—';
            const badgeEl = document.querySelector('#background-card-content [data-field="background_tissue_code_badge"]');
            const codeEl = document.querySelector('#background-card-content [data-field="background_tissue_code"]');
            const textEl = document.querySelector('#background-card-content [data-field="background_tissue_text"]');
            const explainEl = document.querySelector('#background-card-content [data-field="background_tissue_explain"]');

            if (badgeEl) {
                badgeEl.textContent = code.slice(0,1) || '?'; // Show first letter or ?
                // Apply color classes based on code
                badgeEl.className = 'birads-badge'; // Reset classes
                if (code.startsWith('A')) badgeEl.classList.add('birads-a');
                else if (code.startsWith('B')) badgeEl.classList.add('birads-b');
                else if (code.startsWith('C')) badgeEl.classList.add('birads-c');
                else if (code.startsWith('D')) badgeEl.classList.add('birads-d');
            }
            if (codeEl) codeEl.textContent = code;
            if (textEl) textEl.textContent = bg.text ?? '—';
            if (explainEl) explainEl.textContent = bg.explain ?? '—';


            // --- Explanations Card (Rendered by JS function) ---
            const cExp = (Array.isArray(resultData.explanation?.class) && resultData.explanation.class.length > 0) ? resultData.explanation.class.map(e => `${escapeHTML(e)}`).join('<br>') : '—';
            const aSumm = resultData.explanation?.abnormality_summary || '—';

            // === Explanations Renderer ===
            (function renderExplanations() {
                const root = document.getElementById('explain-root');
                if (!root) { console.error("Could not find #explain-root element."); return; }
                const iconInfo = `<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 110-16 8 8 0 010 16zM9 9h2v6H9V9zm1-4a1.25 1.25 0 100 2.5A1.25 1.25 0 0010 5z"/></svg>`;
                const iconShield = `<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2l7 3v5c0 4.418-2.686 7.418-7 8-4.314-.582-7-3.582-7-8V5l7-3z"/></svg>`;
                function decorateMath(s) { if (!s) return ''; return `<span class="math-inline">${s.replace(/<=/g, '≤').replace(/->/g, '→').replace(/\*/g, '·')}</span>`; }
                function metricsToChips(summary) { if (!summary) return ''; const c = []; const re = /([A-Za-z][A-Za-z_ ]+)\s*=\s*([-+]?\d*\.?\d+(?:e[-+]?\d+)?)/gi; let m; while ((m = re.exec(summary)) !== null) c.push(`<span class="metric-chip"><span class="k">${m[1].trim()}</span><span class="v">${Number(m[2]).toFixed(2)}</span></span>`); return c.join(''); }
                function riskBadge(summary) { const s = (summary || '').toLowerCase(); if (s.includes('risk level: high')) return `<span class="badge badge-risk high">${iconShield} High Risk</span>`; if (s.includes('risk level: medium')) return `<span class="badge badge-risk med">${iconShield} Medium Risk</span>`; if (s.includes('risk level: low')) return `<span class="badge badge-risk low">${iconShield} Low Risk</span>`; return ''; }
                function patternBadge(ct, sum) { const b = `${ct||''} ${sum||''}`.toLowerCase(); if (b.includes('→ malignant')||b.includes('malignant pattern')) return `<span class="badge badge-pattern malignant">${iconInfo} Malignant Pattern</span>`; if (b.includes('benign pattern')||b.includes('→ benign')) return `<span class="badge badge-pattern benign">${iconInfo} Benign Pattern</span>`; return ''; }
                const classHTML = `<div class="explain-section"><div class="explain-title"><span class="dot"></span>Class-based</div><div class="explain-body">${decorateMath(cExp || '')}</div></div>`;
                const metricsHTML = metricsToChips(aSumm || '');
                const badgesHTML = `<div class="badge-row">${patternBadge(cExp, aSumm)}${riskBadge(aSumm)}</div>`;
                const summaryHTML = `<div class="explain-section"><div class="explain-title"><span class="dot"></span>Abnormality Summary</div><div class="explain-body">${metricsHTML||''}${badgesHTML}</div></div>`; // Removed aSumm text
                root.innerHTML = classHTML + summaryHTML;
            })();


            // --- Abnormality Scores Chart ---
            const abnScores = resultData.abnormality_scores || {};
            const abnCtx = document.getElementById('abnormality-chart')?.getContext('2d');
            if (abnCtx) {
                if (activeCharts['abnormality-chart']) activeCharts['abnormality-chart'].destroy();
                const abnVals = Object.values(abnScores); const abnLabels = Object.keys(abnScores).map(k => PRETTY_NAMES[k] || k);
                activeCharts['abnormality-chart'] = new Chart(abnCtx, { type: 'bar', data: { labels: abnLabels, datasets: [{ label: 'Score', data: abnVals, backgroundColor: abnVals.map((_, i) => PASTELS[i % PASTELS.length]), borderWidth: 0, borderRadius: 4 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true }, y: { ticks: { font: { size: 11 } } } }, plugins: { legend: { display: false } } } });
            }

            // --- Top Contributors Table (STEP 3.1) ---
            (function(){ const tfc = Array.isArray(resultData.top_feature_contributors)?resultData.top_feature_contributors:[]; const t=tfc.reduce((s,x)=>s+(Number(x?.[1])||0),0)||1; const rows=tfc.map(([n,r])=>{const p=(Number(r)/t)*100; const pr=PRETTY_NAMES[n]||n; return`<tr><td>${escapeHTML(pr)}</td><td class="mono" data-raw-val="${escapeHTML(Number(r).toFixed(4))}" data-pct-val="${escapeHTML(p.toFixed(1)+'%')}"><strong>${escapeHTML(p.toFixed(1)+'%')}</strong></td></tr>`;}).join('')||'<tr><td colspan="2">No data</td></tr>'; 
            const tb=document.querySelector('#tfc-card-content [data-field="top_feature_contributors"]'); // UPDATED SELECTOR
            if(tb)tb.innerHTML=rows; })();

            // --- Stacked Contributions Chart (STEP 3.2) ---
            (function(){
              const cv = document.getElementById('tfc-stacked'); // Use new ID
              if(!cv) return;
              if(activeCharts['tfc-stacked']) activeCharts['tfc-stacked'].destroy(); // Use new ID

              const tfc = Array.isArray(resultData.top_feature_contributors) ? resultData.top_feature_contributors : [];
              const total = tfc.reduce((s, x) => s + (Number(x?.[1]) || 0), 0) || 1;
              const pct = tfc.map(([label, v]) => [ (PRETTY_NAMES[label] || label), (Number(v)/total)*100 ]);
              const datasets = pct.map(([label, value], i) => ({
                label,
                data: [value],
                backgroundColor: PASTELS[i % PASTELS.length],
                borderWidth: 0
              }));

              activeCharts['tfc-stacked'] = new Chart(cv.getContext('2d'), { // Use new ID
                type: 'bar',
                data: { labels: ['Contribution'], datasets },
                options: {
                  indexAxis: 'y',
                  responsive: true,
                  maintainAspectRatio: false,
                  scales: {
                    x: { stacked: true, min: 0, max: 100, ticks: { callback: v => v + '%' }, grid: { color: chartColors.borderColor } },
                    y: { stacked: true, grid: { display: false } }
                  },
                  plugins: { legend: { position: 'bottom', labels: { boxWidth: 14, font: { size: 10 } } } }
                }
              });
            })();
            
            // --- Height Sync (STEP 4) ---
            (function() {
                const tfcChartWrapper = document.getElementById('tfc-chart-wrapper');
                const tfcTableScroll  = document.getElementById('tfc-table-scroll');
                if (tfcChartWrapper && tfcTableScroll) {
                  // This ensures the chart canvas itself has a height to give the wrapper a basis
                  const canvas = document.getElementById('tfc-stacked');
                  if (canvas) canvas.style.height = '260px'; 
                  
                  tfcChartWrapper.style.height = '260px';
                  tfcTableScroll.style.maxHeight = '260px';
                }
            })();

            
            // --- UPDATED: Z-Score Visualizer Chart (ALL features) ---
            (function(){
                const cv = document.getElementById('zscore-chart'); if(!cv) return;
                if(activeCharts['zscore-chart']) activeCharts['zscore-chart'].destroy();
                
                const zs=resultData.zscores||{};
                const zData = Object.keys(zs).map(k => {
                    const z = Number(zs[k]);
                    const p = Number.isFinite(z) ? (normalCdf(z) * 100) : 50; // default to 50 if not a number
                    return {
                        label: PRETTY_NAMES[k] || k,
                        z: z,
                        p: p
                    };
                });
                
                // --- CHANGE: Sort by percentile, highest first ---
                zData.sort((a, b) => b.p - a.p); // Sort by percentile, highest first
                
                // --- CHANGE: Use all data, not slice ---
                const allZData = zData; // No slice
                
                const labels = allZData.map(d => d.label);
                const data = allZData.map(d => d.p);
                const colors = allZData.map(d => d.p > 95 || d.p < 5 ? chartColors.accentWarning : chartColors.accentGlowTint);

                // --- CHANGE: Dynamically set height of containers ---
                const numFeatures = allZData.length;
                const chartHeight = Math.max(400, numFeatures * 18); // 18px per bar, min 400px
                
                const chartWrapper = document.querySelector('#zscores-card-content .zscore-chart-wrapper');
                const tableScroll = document.querySelector('#zscores-card-content .table-wrapper-scroll');

                if (chartWrapper) chartWrapper.style.height = `${chartHeight}px`;
                if (tableScroll) tableScroll.style.maxHeight = `${chartHeight}px`; // Match heights
                
                activeCharts['zscore-chart'] = new Chart(cv.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Percentile (%)',
                            data: data,
                            backgroundColor: colors,
                            borderWidth: 0,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                min: 0,
                                max: 100,
                                ticks: { callback: v => v + '%' },
                                grid: { color: chartColors.borderColor }
                            },
                            y: {
                                ticks: { 
                                    font: { size: 9 } // Smaller font size for many labels
                                }, 
                                grid: { display: false }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: ctx => `Percentile: ${ctx.parsed.x.toFixed(1)}%`
                                }
                            }
                        }
                    }
                });
            })();

            // --- UPDATED: Z-Scores Table (Sort by percentile) ---
            { const zs=resultData.zscores||{}; const ztb=document.querySelector('#zscores-card-content [data-field="zscores"]'); if(ztb){ 
                // --- CHANGE: Create array, sort by percentile (highest first) ---
                const zData = Object.keys(zs).map(k => {
                    const z = Number(zs[k]);
                    const p = Number.isFinite(z) ? (normalCdf(z) * 100) : -Infinity; // Use -Infinity for sorting non-numbers to bottom
                    return {
                        key: k,
                        z: z,
                        p: p
                    };
                });
                
                // Sort by percentile, highest first
                zData.sort((a, b) => b.p - a.p);
                
                const rows = zData.map(d => {
                    const pn=PRETTY_NAMES[d.key]||d.key;
                    const raw=Number.isFinite(d.z)?d.z.toFixed(4):'N/A'; 
                    const pct=Number.isFinite(d.p)?d.p.toFixed(1)+'%':'N/A'; 
                    return`<tr><td>${escapeHTML(pn)}</td><td class="mono" data-raw-val="${escapeHTML(raw)}" data-pct-val="${escapeHTML(pct)}"><strong>${escapeHTML(pct)}</strong></td></tr>`;
                }).join('')||'<tr><td colspan="2">No Z-Score data</td></tr>'; 
                
                ztb.innerHTML=rows; const zh=document.querySelector('#zscores-card-content .zscore-toggle-header .header-text'); if(zh)zh.innerHTML='Percentile (%)'; 
            }}

            // --- Re-attach Maximize Button Listeners ---
            resultsGrid.querySelectorAll('.maximize-card-btn').forEach(b=>{const nb=b.cloneNode(true); b.parentNode.replaceChild(nb,b); nb.addEventListener('click',e=>{const c=e.target.closest('.step-card[id]');if(c?.id){const t=c.querySelector('h2')?.textContent.trim()||'Details'; const ce=c.querySelector('.card-content'); if(ce){const cl=ce.cloneNode(true);let cid=c.querySelector('canvas')?.id||null; 
            // if(c.id==='top-features-card-content')cid=null; // OLD
            if (c.id === 'tfc-card-content') cid = 'tfc-stacked'; // STEP 3.3
            if(c.id==='prediction-card-content')cid='prediction-gauge-chart'; 
            if(c.id==='zscores-card-content')cid='zscore-chart'; 
            showContentInModal(t,cl.innerHTML,cid);}}});});

            // --- Re-attach Print/CSV Listeners ---
            const printBtn = document.getElementById('print-btn'); if (printBtn) { const nPB = printBtn.cloneNode(true); printBtn.parentNode.replaceChild(nPB, printBtn); nPB.addEventListener('click', () => window.print()); }
            const csvBtn = document.getElementById('csv-btn'); if (csvBtn) { const nCB = csvBtn.cloneNode(true); csvBtn.parentNode.replaceChild(nCB, csvBtn); nCB.addEventListener('click', () => openCSVPreview(resultData)); }

        } // End displayResults

        // === CSV Functions (Updated with new fields) ===
        function downloadCSV(rd) {
             let c = "Category,Parameter,Value\r\n";
             const e = (s) => { if (s==null) return ''; let r=String(s); if (r.includes(',')||r.includes('"')||r.includes('\n')) r='"'+r.replace(/"/g,'""')+'"'; return r; };
             c+=`Prediction,final_prediction,${e(rd.final_prediction)}\r\n`;
             if(rd.probabilities) Object.entries(rd.probabilities).forEach(([k,v])=>c+=`Probability,${e(k)},${e(v)}\r\n`);
             c+=`Decision,distance_to_benign,${e(rd.distance_to_benign)}\r\n`;
             c+=`Decision,distance_to_malignant,${e(rd.distance_to_malignant)}\r\n`;
             c+=`Decision,tau,${e(rd.tau)}\r\n`;
             c+=`Decision,ratio_decision_rule,${e(rd.ratio_decision)}\r\n`;
             c+=`Decision,distance_ratio,${e(rd.distance_ratio ?? '')}\r\n`;
             c+=`Decision,malignant_margin_x,${e(rd.malignant_margin_x ?? '')}\r\n`;
             c+=`Decision,malignant_margin_pct,${e(rd.malignant_margin_pct ?? '')}\r\n`;
             c+=`Decision,benign_margin_x,${e(rd.benign_margin_x ?? '')}\r\n`;
             c+=`Decision,benign_margin_pct,${e(rd.benign_margin_pct ?? '')}\r\n`;
             c+=`Decision,decision_verdict,${e(rd.decision_verdict ?? '')}\r\n`;
             c+=`Abnormality,type,${e(rd.abnormality_type)}\r\n`;
             if(rd.abnormality_scores) Object.entries(rd.abnormality_scores).forEach(([k,v])=>c+=`Abnormality Score,${e(PRETTY_NAMES[k] || k)},${e(v)}\r\n`);
             if(rd.background_tissue){ c+=`Background,code,${e(rd.background_tissue.code)}\r\n`; c+=`Background,text,${e(rd.background_tissue.text)}\r\n`; c+=`Background,explanation,${e(rd.background_tissue.explain)}\r\n`; }
             if(rd.explanation?.class) c+=`Explanation,class,${e(Array.isArray(rd.explanation.class) ? rd.explanation.class.join('; ') : rd.explanation.class)}\r\n`;
             if(rd.explanation?.abnormality_summary) c+=`Explanation,abnormality_summary,${e(rd.explanation.abnormality_summary)}\r\n`;
             if(rd.top_feature_contributors) rd.top_feature_contributors.forEach(([n,v])=>c+=`Feature Contribution,${e(PRETTY_NAMES[n] || n)},${e(v)}\r\n`);
             if(rd.zscores) Object.keys(rd.zscores).sort().forEach(k => c+=`Z-Score,${e(PRETTY_NAMES[k] || k)},${e(rd.zscores[k])}\r\n`);

             const b=new Blob([c],{type:'text/csv;charset=utf-8;'}); const l=document.createElement("a");
             if(l.download!==undefined){ const u=URL.createObjectURL(b); const t=new Date().toISOString().replace(/:/g,'-').slice(0,19); l.setAttribute("href",u); l.setAttribute("download",`prediction_results_${t}.csv`); l.style.visibility='hidden'; document.body.appendChild(l); l.click(); document.body.removeChild(l); }
           }
        function buildCSVPreviewHTML(rd, l=35) {
             const rs=[];
             rs.push(["Prediction","final_prediction",String(rd.final_prediction??"—")]);
             if(rd.probabilities)Object.entries(rd.probabilities).forEach(([k,v])=>rs.push(["Probability",k,String(v??"—")]));
             rs.push(["Decision","distance_to_benign",String(rd.distance_to_benign??"—")]);
             rs.push(["Decision","distance_to_malignant",String(rd.distance_to_malignant??"—")]);
             rs.push(["Decision","tau",String(rd.tau??"—")]);
             rs.push(["Decision","ratio_decision_rule",String(rd.ratio_decision??"—")]);
             rs.push(["Decision","distance_ratio",String(rd.distance_ratio ?? "—")]);
             rs.push(["Decision","malignant_margin_x",String(rd.malignant_margin_x ?? "—")]);
             rs.push(["Decision","malignant_margin_pct",String(rd.malignant_margin_pct ?? "—")]);
             rs.push(["Decision","benign_margin_x",String(rd.benign_margin_x ?? "—")]);
             rs.push(["Decision","benign_margin_pct",String(rd.benign_margin_pct ?? "—")]);
             rs.push(["Decision","decision_verdict",String(rd.decision_verdict ?? "—")]);
             rs.push(["Abnormality","type",String(rd.abnormality_type??"—")]);
             if(rd.abnormality_scores)Object.entries(rd.abnormality_scores).forEach(([k,v])=>rs.push(["Abnormality Score", PRETTY_NAMES[k] || k ,String(v??"—")]));
             if(rd.background_tissue){rs.push(["Background","code",String(rd.background_tissue.code??"—")]); rs.push(["Background","text",String(rd.background_tissue.text??"—")]); rs.push(["Background","explanation",String(rd.background_tissue.explain??"—")]);}
             if(rd.explanation?.class) rs.push(["Explanation","class",String(Array.isArray(rd.explanation.class) ? rd.explanation.class.join("; ") : rd.explanation.class)]);
             if(rd.explanation?.abnormality_summary)rs.push(["Explanation","abnormality_summary",String(rd.explanation.abnormality_summary)]);
             if(Array.isArray(rd.top_feature_contributors))rd.top_feature_contributors.forEach(([n,v])=>rs.push(["Feature Contribution", PRETTY_NAMES[n] || n ,String(v??"—")]));
             if(rd.zscores) Object.keys(rd.zscores).sort().forEach(k => rs.push(["Z-Score", PRETTY_NAMES[k] || k, String(rd.zscores[k] ?? "—")]));

             const lim=rs.slice(0,l); let t='<div class="table-wrapper-scroll"><table class="data-table"><thead><tr><th>Category</th><th>Parameter</th><th>Value</th></tr></thead><tbody>'; lim.forEach(r=>t+=`<tr><td>${escapeHTML(r[0])}</td><td>${escapeHTML(r[1])}</td><td>${escapeHTML(r[2])}</td></tr>`); t+='</tbody></table></div>'; t+=`<div class="modal__actions" style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem;"><button type="button" class="btn" id="csv-download-confirm">Download All ${rs.length} Rows</button><button type="button" class="btn btn-secondary" id="csv-preview-close">Close</button></div><p class="file-meta" style="margin-top:.5rem; text-align:right;">Showing first ${lim.length} of ${rs.length} rows.</p>`; return t;
           }
        function openCSVPreview(rd) { const h=buildCSVPreviewHTML(rd); showContentInModal('CSV Preview', h); const dl=document.getElementById('csv-download-confirm'); const cl=document.getElementById('csv-preview-close'); if(dl)dl.addEventListener('click',()=>{downloadCSV(rd); closeCardModal();}); if(cl)cl.addEventListener('click',closeCardModal); }


        // --- Initialization ---
        const initialPredictData = window.__PREDICT__;
        const stored = loadState();
        let initialImageSrc = null;

        // Determine initial state (PHP load > LocalStorage > Default)
        if (initialPredictData?.result) { // Result from PHP
            resultsPlaceholder.style.display = 'none';
            displayResults(initialPredictData.result);
            clearBtn.style.display = 'inline-flex';
            initialImageSrc = window.__UPLOADED_IMAGE__;
        } else if (stored?.result) { // Result from localStorage
            resultsPlaceholder.style.display = 'none';
            displayResults(stored.result);
            clearBtn.style.display = 'inline-flex';
            initialImageSrc = stored.imagePath || stored.previewDataUrl;
             window.__PREDICT__ = { ok: true, result: stored.result, image: stored.imagePath };
        } else { // Default initial state
            resultsPlaceholder.style.display = 'block';
            resultsContainer.style.display = 'none';
            skeletonLoader.style.display = 'none';
        }

        // Display initial image if available
        if (initialImageSrc) {
            const img = new Image();
            img.onload = () => {
                const rC = document.createElement('canvas'); rC.width = img.width; rC.height = img.height; rC.getContext('2d').drawImage(img, 0, 0);
                const dU = rC.toDataURL(); previewWrapper.dataset.fullImage = dU;
                const mW = previewWrapper.clientWidth || 900; const mH = 400; const sc = scaleCanvasToFit(rC, mW, mH);
                displayCanvas(sc, previewWrapper);
                const nE = document.getElementById('image-filename'); if (nE) { nE.textContent = (initialPredictData ? window.__UPLOADED_IMAGE__?.split('/').pop() : stored?.filename) || 'image'; nE.style.display = 'block'; }
                submitBtn.disabled = false;
                uploadArea.style.display = 'none';
            };
            img.onerror = () => { console.warn("Could not load initial image:", initialImageSrc); clearState(); };
            img.src = initialImageSrc;
        }

        // === Event listener for Toggles (Value + Details) ===
        document.body.addEventListener('click', e => {
            // --- Value Toggler (Raw/Pct) ---
            const valueToggleBtn = e.target.closest('.btn-toggle-values');
            if (valueToggleBtn) {
                const currentState = valueToggleBtn.dataset.state || 'pct';
                const newState = currentState === 'pct' ? 'raw' : 'pct';
                valueToggleBtn.dataset.state = newState;
                const span = valueToggleBtn.querySelector('span'); if (span) span.textContent = (newState === 'pct') ? 'Show Raw Values' : 'Show Percentages';

                const setHeader = (selector, pctTextAttr = 'pctText', rawTextAttr = 'rawText') => { const w=document.querySelector(selector); if(!w)return; const h=w.querySelector('.header-text'); if(!h)return; h.innerHTML=w.dataset[(newState==='pct')?pctTextAttr:rawTextAttr]||h.innerHTML; };
                const swapCells = (containerSelector) => { const c=document.querySelector(containerSelector); if(!c)return; c.querySelectorAll('td[data-raw-val][data-pct-val]').forEach(td=>{const v=td.dataset[(newState==='pct')?'pctVal':'rawVal']||'—';if(td.querySelector('strong'))td.innerHTML=`<strong>${v}</strong>`;else td.textContent=v;}); };

                // Apply to Prediction Card Headers + Cells
                document.querySelectorAll('#prediction-card-content .dist-toggle-header').forEach(h=>{const hdr=h.querySelector('.header-text');if(hdr)hdr.innerHTML=h.dataset[(newState==='pct')?'pctText':'rawText']||hdr.innerHTML;});
                ['[data-field="distance_to_benign"]','[data-field="distance_to_malignant"]','[data-field="tau"]','[data-field="distance_ratio"]','[data-field="mal_margin"]','[data-field="ben_margin"]'].forEach(sel=>{const td=document.querySelector(`#prediction-card-content ${sel}`);if(td){const v=td.dataset[(newState==='pct')?'pctVal':'rawVal']||'—';if(sel.includes('margin'))td.innerHTML=`<strong>${v}</strong>`;else td.textContent=v;}});

                // Apply to Top Features Card (STEP 3.4)
                setHeader('#tfc-card-content .contrib-toggle-header');
                swapCells('#tfc-card-content');
                // Apply to Z-Scores Card
                setHeader('#zscores-card-content .zscore-toggle-header'); swapCells('#zscores-card-content');
            }

            // --- Collapsible Details Toggler ---
            const detailsToggleBtn = e.target.closest('.btn-toggle-details');
            if (detailsToggleBtn) {
                const wrapper = detailsToggleBtn.closest('.toggle-wrapper'); if (!wrapper) return;
                const content = wrapper.nextElementSibling;
                if (content?.classList.contains('collapsible-content')) {
                    detailsToggleBtn.classList.toggle('is-active'); content.classList.toggle('is-visible');
                    const span = detailsToggleBtn.querySelector('span'); if (span) { span.textContent = content.classList.contains('is-visible') ? 'Hide Decision Logic' : 'Show Decision Logic'; }
                }
            }
        }); // End of body click listener

        // --- Reset details button/panel state on initial load ---
        const initialDetailsBtn = document.querySelector('#prediction-card-content .btn-toggle-details');
        const initialDetailsPanel = document.querySelector('#prediction-card-content .decision-details');
        if (initialDetailsBtn && initialDetailsPanel) {
            initialDetailsBtn.classList.remove('is-active');
            initialDetailsPanel.classList.remove('is-visible');
            const initialLabel = initialDetailsBtn.querySelector('span');
            if (initialLabel) initialLabel.textContent = 'Show Decision Logic';
        }


    });
    </script>

</body>
</html>

