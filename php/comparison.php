<?php
session_start();

/* ──────────────────────────────────────────────────────────────────────────
   1) Handle Reset Action
────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset') {
    unset($_SESSION['comparison_result'], $_SESSION['comparison_image'], $_SESSION['comparison_error']);
    header('Location: comparison.php');
    exit;
}

/* ──────────────────────────────────────────────────────────────────────────
   2) Load Config & Session State
────────────────────────────────────────────────────────────────────────── */
$config  = require __DIR__ . '/config.php';
$python  = $config['python_path'] ?? null;
$workdir = $config['workdir'] ?? null;

$result             = $_SESSION['comparison_result'] ?? null;
$uploaded_image_src = $_SESSION['comparison_image']  ?? null;
$error              = $_SESSION['comparison_error']  ?? null;

//
// +++ NEW: Added Pretty Names definition (copied from index.php) +++
//
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
// +++ END Pretty Names definition +++


// NEW: Helper function to translate feature lists
function translate_features($features, $pretty_names) {
    if (!is_array($features)) return 'N/A';
    $translated = array_map(function($f) use ($pretty_names) {
        return htmlspecialchars($pretty_names[$f] ?? $f);
    }, $features);
    return implode(', ', $translated);
}

// Clear error after showing it once
unset($_SESSION['comparison_error']);

// --- ADDED FOR DEBUGGING ---
$debug_info = [];
// --- END DEBUGGING ---


/* ──────────────────────────────────────────────────────────────────────────
   3) Handle File Upload & Backend Call
────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']) && $_FILES['image']['error'] == 0) {

    // --- A. Handle File Upload ---
    $uploadDir = __DIR__ . '/test_uploads/';
    @mkdir($uploadDir, 0777, true);

    // Use the original filename (sanitized by basename)
    // This allows the Python script to match the basename in the CSV.
    $originalName  = basename($_FILES['image']['name']);
    $imagePath     = $uploadDir . $originalName; // Use original name, not safeName

    if (move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
        $uploaded_image_src = 'test_uploads/' . $originalName; // Use original name here too

        try {
            // --- B. Build Python Command (Reliably) ---
            if (empty($python) || empty($workdir)) {
                throw new Exception("Config Error: 'python_path' or 'workdir' is not set in config.php.");
            }

            // 1. Resolve CSV Path from multiple candidates & DEBUG
            $csv_candidate_cfg = $config['csv_path'] ?? null;
            $csv_candidate1 = $workdir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ground_truth.csv';
            $csv_candidate2 = $workdir . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ground_truth.csv';
            $csv_candidates = array_values(array_unique(array_filter([$csv_candidate_cfg, $csv_candidate1, $csv_candidate2])));
            $csv_path = null;
            foreach ($csv_candidates as $cand) {
                if ($cand && file_exists($cand) && is_readable($cand)) { $csv_path = $cand; break; }
            }
            $csv_arg = $csv_path ? (' --csv ' . escapeshellarg($csv_path)) : '';

            // --- DEBUG BLOCK ---
            $debug_info[] = "--- CSV Path Debug ---";
            $debug_info[] = "Config 'csv_path': " . ($csv_candidate_cfg ?? 'NOT SET');
            $debug_info[] = "Tried candidates:";
            foreach ($csv_candidates as $cand) {
                $debug_info[] = sprintf(" - %s | exists: %s | readable: %s", $cand, file_exists($cand) ? 'true' : 'false', is_readable($cand) ? 'true' : 'false');
            }
            $debug_info[] = "Selected CSV: " . ($csv_path ?? 'NONE');
            $open_basedir = ini_get('open_basedir');
            $debug_info[] = "PHP open_basedir: " . ($open_basedir ? $open_basedir : 'NOT SET (Good!)');
            // --- END DEBUG BLOCK ---
            
            // 2. Get Model Paths (from config)
            $ewoa_model_path = $config['models']['ewoa'] ?? ($workdir . '/models/model_final_ewoa.json');
            $woa_model_path  = $config['models']['woa']  ?? ($workdir . '/models/model_woa.json');
            
            if (!file_exists($ewoa_model_path)) error_log('[comparison.php] ERROR: EWOA model not found: ' . $ewoa_model_path);
            if (!file_exists($woa_model_path))  error_log('[comparison.php] ERROR: WOA model not found: ' . $woa_model_path);

            // 3. Construct the final command
            $inner_cmd = sprintf(
              'set "PYTHONPATH=%s" && %s -m woa_tool.compare_predict --image %s --ewoa %s --woa %s%s',
              $workdir,
              $python,
              escapeshellarg($imagePath),
              escapeshellarg($ewoa_model_path),
              escapeshellarg($woa_model_path),
              $csv_arg
            );
            $cmd = 'cmd /C "' . $inner_cmd . '"';
            $debug_info[] = "--- Command ---";
            $debug_info[] = $cmd; // Show the exact command we tried to run

            // --- C. Execute Python Script ---
            $stdout_str = ''; $stderr_str = ''; $code = -1;
            $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
            $process = proc_open($cmd, $descriptorspec, $pipes, $workdir);

            if (is_resource($process)) {
                fclose($pipes[0]);
                $stdout_str = stream_get_contents($pipes[1]); fclose($pipes[1]);
                $stderr_str = stream_get_contents($pipes[2]); fclose($pipes[2]);
                $code = proc_close($process);
            } else {
                throw new Exception("Failed to execute Python script (proc_open failed).");
            }

            // --- D. Process Python Output ---
            $decoded = json_decode(trim($stdout_str), true, 512, JSON_BIGINT_AS_STRING);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['WOA'], $decoded['EWOA'])) {
                // SUCCESS!
                $_SESSION['comparison_result'] = $decoded;
                $_SESSION['comparison_image']  = $uploaded_image_src;
                unset($_SESSION['comparison_error']);

                // If Ground Truth is *still* N/A, show the debug info as an error
                if (($decoded['Ground Truth'] ?? 'N/A') === 'N/A (no ground truth)') {
                     $_SESSION['comparison_error'] = "Python ran, but 'Ground Truth' was N/A. This means the --csv argument failed.<br><strong>Debug Info:</strong><pre>" . htmlspecialchars(implode("\n", $debug_info)) . "</pre>";
                }

            } else {
                // JSON was invalid or backend script failed
                $jsonErrorMsg = json_last_error_msg();
                $errorMsg  = "Failed to get valid JSON from Python script (Code: $code, JSON Error: $jsonErrorMsg).";
                if(!empty($stderr_str)) { $errorMsg .= "<br><strong>Stderr (Python Error):</strong><pre>" . htmlspecialchars($stderr_str) . "</pre>"; }
                if(!empty($stdout_str) && json_last_error() !== JSON_ERROR_NONE) { $errorMsg .= "<br><strong>Raw Stdout:</strong><pre>" . htmlspecialchars(trim($stdout_str)) . "</pre>"; }
                if(empty($stderr_str) && empty($stdout_str) && $code !== 0) { $errorMsg .= "<br><strong>Details:</strong> The script exited with code $code but produced no output."; }
                $errorMsg .= "<br><strong>Debug Info:</strong><pre>" . htmlspecialchars(implode("\n", $debug_info)) . "</pre>";
                throw new Exception($errorMsg);
            }

        } catch (Exception $e) {
            // Catch any PHP error (proc_open, config missing, etc)
            $errorMsg = $e->getMessage();
            if (!empty($debug_info)) {
                 $errorMsg .= "<br><strong>Debug Info:</strong><pre>" . htmlspecialchars(implode("\n", $debug_info)) . "</pre>";
            }
            $_SESSION['comparison_error'] = $errorMsg;
            unset($_SESSION['comparison_result']);
            $_SESSION['comparison_image'] = $uploaded_image_src; // Keep image on error
        }
    } else {
        // move_uploaded_file failed
        $_SESSION['comparison_error'] = "Failed to move uploaded file. Check directory permissions for '$uploadDir'.";
        unset($_SESSION['comparison_result'], $_SESSION['comparison_image']);
    }

    // --- E. Redirect after POST ---
    header('Location: comparison.php');
    exit;
}
/* ──────────────────────────────────────────────────────────────────────────
   (End of PHP Logic)
────────────────────────────────────────────────────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>WOA vs EWOA Comparison | WOA-Tool</title>
  <link rel="stylesheet" href="style.css?v=29" /> <!-- Version bump -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <!-- Use full Chart.js -->
  
  <!-- Inline styles removed, will be provided in separate CSS file -->

</head>
<body id="page-comparison">
  <!-- Top nav (same as index.php) -->
  <header class="main-header">
    <div class="header-inner">
      <div class="header-left">
        <div class="header-logo">🐋</div>
        <div class="header-title">
          <h1>WOA: <span>Balancing Exploration–Exploitation</span></h1>
          <p>for Breast Cancer Feature Detection</p>
        </div>
      </div>
      <nav class="header-nav">
        <a href="index.php"     class="<?= basename($_SERVER['PHP_SELF'])==='index.php'      ? 'active' : '' ?>">Feature Detection</a>
        <a href="benchmark_backend.php" class="<?= basename($_SERVER['PHP_SELF'])==='benchmark_backend.php'  ? 'active' : '' ?>">Benchmark Functions</a>
        <a href="comparison.php" class="<?= basename($_SERVER['PHP_SELF'])==='comparison.php' ? 'active' : '' ?>">Comparison</a>
      </nav>
    </div>
  </header>

  <div id="aurora-background"></div>

  <div class="main-container">
    <!-- Page header -->
    <div class="header">
      <h1>
        <span class="header-logo" style="font-size:2.2rem;width:60px;height:60px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
               viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="6" y1="21" x2="6" y2="3"></line>
            <line x1="18" y1="21" x2="18" y2="3"></line>
            <line x1="2" y1="12" x2="22" y2="12"></line>
          </svg>
        </span>
        WOA vs. EWOA Comparison
      </h1>
      <p>Upload a mammogram image to see a side-by-side comparison of the two methods.</p>
    </div>

    <!-- ── Upload card: ALWAYS AT TOP, full width ───────────────────────── -->
    <div class="step-card animate-slide-up" style="grid-column:1 / -1;">
      <div class="step-header">
        <div class="step-header-left">
          <div class="step-number" style="background: var(--text-light);">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="17 8 12 3 7 8"></polyline>
              <line x1="12" y1="3" x2="12" y2="15"></line>
            </svg>
          </div>
          <h2>Upload Image</h2>
        </div>
      </div>

      <form id="comparison-form" method="POST" enctype="multipart/form-data">
        <div id="image-preview-wrapper" style="display: <?= $uploaded_image_src ? 'flex' : 'none' ?>; background:#fff;">
          <!-- UPDATED: Set max-width to 400px and auto width -->
          <img id="image-preview"
               src="<?= htmlspecialchars($uploaded_image_src ?? '#') ?>"
               alt="Image preview"
               style="max-width:400px; max-height:300px; width: auto; border-radius:var(--border-radius-small);" />
        </div>

        <label for="image-upload" class="upload-area" id="upload-area"
               style="display: <?= $uploaded_image_src ? 'none' : 'block' ?>;">
          <svg class="upload-area__icon" xmlns="http://www.w3.org/2000/svg"
               width="24" height="24" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round">
            <path d="M21.2 15c.7-1.2 1-2.5.7-3.9-.6-2.4-2.4-4.2-4.8-4.8-1.4-.3-2.7-.1-3.9.7L12 8l-1.2-1.1c-1.2-.8-2.5-1-3.9-.7-2.4.6-4.2 2.4-4.8 4.8-.3 1.4-.1 2.7.7 3.9L4 16.5 12 22l8-5.5-2.8-1.5z"></path>
            <path d="M12 8v8"></path>
          </svg>
          <p class="upload-area__text"><span>Click to upload</span> or drag & drop</p>
        </label>
        <input type="file" id="image-upload" name="image" accept="image/*" />
        <p class="file-meta" id="file-meta-text" style="display:none;text-align:center;"></p>

        <div class="form-buttons mt-3">
          <button type="submit" id="run-comparison-btn" class="btn btn-primary-full" disabled>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="6" y1="21" x2="6" y2="3"></line>
              <line x1="18" y1="21" x2="18" y2="3"></line>
              <line x1="2" y1="12" x2="22" y2="12"></line>
            </svg>
            <span id="btn-text">Run Comparison</span>
          </button>
          <button type="submit" name="action" value="reset" id="reset-btn" class="btn btn-secondary"
                  <?= (!$result && !$uploaded_image_src && !$error) ? 'disabled' : '' ?>>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="23 4 23 10 17 10"></polyline>
              <polyline points="1 20 1 14 7 14"></polyline>
              <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
            </svg>
            Reset
          </button>
        </div>
      </form>
    </div>

    <!-- ── Results/Error below uploader ─────────────────────────────────── -->
    <div id="results-wrapper" style="grid-column:1 / -1;">

      <?php if ($error): ?>
        <div class="step-card error-card animate-slide-up" style="grid-column:1 / -1;">
          <div class="step-header">
            <div class="step-header-left">
              <div class="step-number" style="background: var(--accent-warning);">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="12" y1="8" x2="12" y2="12"></line>
                  <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
              </div>
              <h2>Error</h2>
            </div>
          </div>
          <p>An error occurred:</p>
          <div><?= $error ?></div>
        </div>
      
      <!-- NEW: Skeleton Loader (from index.php) -->
      <?php elseif (!$result): ?>
        <div class="skeleton-container animate-slide-up" id="skeleton-loader" style="display: none;">
           <div class="step-card loader-card">
              <div class="loader-inner">
                 <div class="scan-loader">
                    <span></span><span></span><span></span><span></span>
                 </div>
                 <p class="loader-caption">Running comparison... please wait</p>
              </div>
           </div>
        </div>

        <!-- Placeholder when no results yet -->
        <div id="comparison-placeholder" class="placeholder-card single-placeholder animate-slide-up" style="display: <?= $result ? 'none' : 'block' ?>;">
          <div class="step-header">
            <div class="step-header-left">
              <div class="step-number" style="background: var(--text-dark); opacity: 0.5;">?</div>
              <h2>Results</h2>
            </div>
          </div>
          <div class="placeholder-content">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
              <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
            </svg>
            <h3>Waiting for Image</h3>
            <p>Upload a mammogram image to begin the comparison.</p>
          </div>
        </div>
      <?php endif; ?>

      <!-- Main Results Container -->
      <div id="comparison-results" class="animate-slide-up" style="grid-column:1 / -1; display: <?= $result ? 'block' : 'none' ?>;">
        
        <?php 
          // NEW: Pre-calculate outcomes for confusion matrix
          if ($result) {
            $woa_outcome = $result['WOA']['Outcome'] ?? 'N/A';
            $ewoa_outcome = $result['EWOA']['Outcome'] ?? 'N/A';
          }
        ?>

        <!-- Ground truth + overall -->
        <div class="step-card" style="margin-bottom:1.5rem;">
          <div class="step-header">
            <div class="step-header-left">
              <div class="step-number">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"></path>
                  <path d="m9 12 2 2 4-4"></path>
                </svg>
              </div>
              <h2>Overall Summary</h2>
            </div>
          </div>
          
          <!-- UPDATED: New classes for banners -->
          <?php if (isset($result['Ground Truth']) && ($result['Ground Truth'] ?? '') !== 'N/A (no ground truth)'): 
            $gt_class = ($result['Ground Truth'] ?? '') === 'Malignant' ? 'malignant' : 'benign';
          ?>
            <p class="ground-truth-banner <?= $gt_class ?>">
              <span class="banner-icon">i</span>
              Ground Truth Label: <strong><?= htmlspecialchars($result['Ground Truth']) ?></strong>
              <span class="tooltip-icon">?<span class="tooltip-content">Provided via CSV/CLI and used to compute correctness.</span></span>
            </p>
          <?php elseif (isset($result['Ground Truth'])): ?>
            <p class="ground-truth-banner warning">
              <span class="banner-icon">!</span>
              Ground Truth Label: <strong>Not Provided</strong>
              <span class="tooltip-icon">?<span class="tooltip-content">No ground truth → cannot compute accuracy.</span></span>
            </p>
          <?php endif; ?>

          <?php if (isset($result['Correct Classification'])): 
            $cc_class = ($result['Correct Classification'] ?? '') === 'Malignant' ? 'malignant' : 'benign';
            if (($result['Correct Classification'] ?? '') === 'N/A (no ground truth)') $cc_class = 'warning';
          ?>
            <p class="classification-banner <?= $cc_class ?>">
              <span class="banner-icon">i</span>
              Correct Classification (backend): <strong><?= htmlspecialchars($result['Correct Classification']) ?></strong>
              <span class="tooltip-icon">?<span class="tooltip-content">Backend’s final call for this image.</span></span>
            </p>
          <?php endif; ?>

          <?php
            $woa_time  = (float)($result['WOA']['Execution Time']  ?? 0);
            $ewoa_time = (float)($result['EWOA']['Execution Time'] ?? 0);
            $time_diff = $woa_time - $ewoa_time;
            $percent_diff = ($woa_time > 0) ? ($time_diff / $woa_time) * 100 : 0;
          ?>
          <div class="comparison-summary">
             <div class="summary-metric">
              <span class="metric-label">WOA Runtime
                <span class="tooltip-icon">?<span class="tooltip-content">Execution time for Standard WOA.</span></span>
              </span>
              <span class="metric-value"><?= htmlspecialchars(number_format($woa_time, 3)) ?> s</span>
            </div>
             <div class="summary-metric">
              <span class="metric-label">EWOA Runtime
                <span class="tooltip-icon">?<span class="tooltip-content">Execution time for Enhanced WOA.</span></span>
              </span>
              <span class="metric-value"><?= htmlspecialchars(number_format($ewoa_time, 3)) ?> s</span>
            </div>
            <div class="summary-metric">
              <span class="metric-label">Time Improvement
                <span class="tooltip-icon">?<span class="tooltip-content">Positive means EWOA was faster vs WOA.</span></span>
              </span>
              <span class="metric-value <?= $time_diff >= 0 ? 'value-benign' : 'value-malignant' ?>">
                <?php
                  if ($woa_time == 0)      echo 'N/A';
                  elseif ($time_diff >= 0) echo 'EWOA ' . number_format($percent_diff, 1) . '% faster';
                  else                     echo 'EWOA ' . number_format(abs($percent_diff), 1) . '% slower';
                ?>
              </span>
            </div>
            <div class="summary-metric">
              <span class="metric-label">Total Python Runtime
                <span class="tooltip-icon">?<span class="tooltip-content">Sum of both runs.</span></span>
              </span>
              <span class="metric-value"><?= htmlspecialchars(number_format($woa_time + $ewoa_time, 3)) ?> s</span>
            </div>
          </div>
        </div>

        <!-- Side-by-side result cards -->
        <div class="comparison-grid" style="margin-bottom: 2rem;">
          <!-- WOA -->
          <div class="step-card comparison-card">
            <div class="step-header">
              <div class="step-header-left">
                <div class="step-number" style="background: var(--text-dark);">W</div>
                <h2>Standard WOA</h2>
              </div>
              <button class="maximize-card-btn" data-modal-title="Standard WOA Results" data-modal-type="content" data-modal-target="#woa-card-content">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg>
              </button>
            </div>

            <div id="woa-card-content">
              <ul class="comparison-metrics simplified">
                <li>
                  <span class="metric-label">Prediction
                    <span class="tooltip-icon">?<span class="tooltip-content">Benign vs Malignant classification.</span></span>
                  </span>
                  <span class="metric-value <?= ($result['WOA']['Prediction'] ?? '') === 'Malignant' ? 'value-malignant' : 'value-benign' ?>" data-field="woa-prediction">
                    <?= htmlspecialchars($result['WOA']['Prediction'] ?? 'N/A') ?>
                  </span>
                </li>
                <li>
                  <span class="metric-label">Confidence
                    <span class="tooltip-icon">?<span class="tooltip-content">Distance-ratio-based certainty (≈1 is high).</span></span>
                  </span>
                  <span class="metric-value" data-field="woa-confidence"><?= htmlspecialchars(number_format((float)($result['WOA']['Confidence'] ?? 0), 3)) ?></span>
                </li>
                <li>
                  <span class="metric-label">Exec. Time
                    <span class="tooltip-icon">?<span class="tooltip-content">Seconds for this run.</span></span>
                  </span>
                  <span class="metric-value" data-field="woa-time"><?= htmlspecialchars(number_format((float)($result['WOA']['Execution Time'] ?? 0), 3)) ?> s</span>
                </li>
                
                <?php if (isset($result['Ground Truth']) && ($result['Ground Truth'] ?? '') !== 'N/A (no ground truth)'): ?>
                  <li>
                    <span class="metric-label">Accuracy
                      <span class="tooltip-icon">?<span class="tooltip-content">Correctness vs ground truth.</span></span>
                    </span>
                    <span class="metric-value <?= ($result['WOA']['Correct'] ?? null) === true ? 'value-benign' : (($result['WOA']['Correct'] ?? null) === false ? 'value-malignant' : '') ?>" data-field="woa-accuracy">
                      <?= isset($result['WOA']['Correct']) ? ($result['WOA']['Correct'] ? 'Correct' : 'Incorrect') : 'N/A' ?>
                      (<?= htmlspecialchars($result['WOA']['Outcome'] ?? 'N/A') ?>)
                    </span>
                  </li>
                <?php endif; ?>

                <li class="collapsible-container">
                  <button type="button" class="details-toggle-btn" data-target="#woa-details-content">Show Technical Details</button>
                  <div id="woa-details-content" class="collapsible-content">
                    <ul class="comparison-metrics nested-details">
                      <li><span class="metric-label nested">Dist. Ratio</span><span class="metric-value nested" data-field="woa-ratio"><?= htmlspecialchars(number_format((float)($result['WOA']['Distance Ratio'] ?? 0), 4)) ?></span></li>
                      <li><span class="metric-label nested">Threshold (τ)</span><span class="metric-value nested" data-field="woa-tau"><?= htmlspecialchars(number_format((float)($result['WOA']['Tau Used'] ?? 0), 4)) ?></span></li>
                      <li><span class="metric-label nested">Top Features</span>
                          <span class="metric-value metric-features nested" data-field="woa-features">
                            <!-- UPDATED: Use PHP function to translate features -->
                            <?= translate_features($result['WOA']['Top Features'] ?? null, $pretty_names) ?>
                          </span>
                      </li>
                    </ul>
                  </div>
                </li>
              </ul>
            </div>
          </div>

          <!-- EWOA -->
          <div class="step-card comparison-card ewoa-card">
            <div class="step-header">
              <div class="step-header-left">
                <div class="step-number" style="background: var(--accent-glow);">E</div>
                <h2>Enhanced WOA</h2>
              </div>
              <button class="maximize-card-btn" data-modal-title="Enhanced WOA Results" data-modal-type="content" data-modal-target="#ewoa-card-content">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg>
              </button>
            </div>

            <div id="ewoa-card-content">
              <ul class="comparison-metrics simplified">
                <li>
                  <span class="metric-label">Prediction
                    <span class="tooltip-icon">?<span class="tooltip-content">Benign vs Malignant classification.</span></span>
                  </span>
                  <span class="metric-value <?= ($result['EWOA']['Prediction'] ?? '') === 'Malignant' ? 'value-malignant' : 'value-benign' ?>" data-field="ewoa-prediction">
                    <?= htmlspecialchars($result['EWOA']['Prediction'] ?? 'N/A') ?>
                  </span>
                </li>
                <li>
                  <span class="metric-label">Confidence
                    <span class="tooltip-icon">?<span class="tooltip-content">Distance-ratio-based certainty (≈1 is high).</span></span>
                  </span>
                  <span class="metric-value" data-field="ewoa-confidence"><?= htmlspecialchars(number_format((float)($result['EWOA']['Confidence'] ?? 0), 3)) ?></span>
                </li>
                <li>
                  <span class="metric-label">Exec. Time
                    <span class="tooltip-icon">?<span class="tooltip-content">Seconds for this run.</span></span>
                  </span>
                  <span class="metric-value" data-field="ewoa-time"><?= htmlspecialchars(number_format((float)($result['EWOA']['Execution Time'] ?? 0), 3)) ?> s</span>
                </li>
                
                <?php if (isset($result['Ground Truth']) && ($result['Ground Truth'] ?? '') !== 'N/A (no ground truth)'): ?>
                  <li>
                    <span class="metric-label">Accuracy
                      <span class="tooltip-icon">?<span class="tooltip-content">Correctness vs ground truth.</span></span>
                    </span>
                    <span class="metric-value <?= ($result['EWOA']['Correct'] ?? null) === true ? 'value-benign' : (($result['EWOA']['Correct'] ?? null) === false ? 'value-malignant' : '') ?>" data-field="ewoa-accuracy">
                      <?= isset($result['EWOA']['Correct']) ? ($result['EWOA']['Correct'] ? 'Correct' : 'Incorrect') : 'N/A' ?>
                      (<?= htmlspecialchars($result['EWOA']['Outcome'] ?? 'N/A') ?>)
                    </span>
                  </li>
                <?php endif; ?>

                <li class="collapsible-container">
                  <button type="button" class="details-toggle-btn" data-target="#ewoa-details-content">Show Technical Details</button>
                  <div id="ewoa-details-content" class="collapsible-content">
                    <ul class="comparison-metrics nested-details">
                      <li><span class="metric-label nested">Dist. Ratio</span><span class="metric-value nested" data-field="ewoa-ratio"><?= htmlspecialchars(number_format((float)($result['EWOA']['Distance Ratio'] ?? 0), 4)) ?></span></li>
                      <li><span class="metric-label nested">Threshold (τ)</span><span class="metric-value nested" data-field="ewoa-tau"><?= htmlspecialchars(number_format((float)($result['EWOA']['Tau Used'] ?? 0), 4)) ?></span></li>
                      <li><span class="metric-label nested">Top Features</span>
                          <span class="metric-value metric-features nested" data-field="ewoa-features">
                            <!-- UPDATED: Use PHP function to translate features -->
                            <?= translate_features($result['EWOA']['Top Features'] ?? null, $pretty_names) ?>
                          </span>
                      </li>
                    </ul>
                  </div>
                </li>
              </ul>
            </div>
          </div>
        </div> <!-- /comparison-grid -->

        <!-- UPDATED: Key Metrics Comparison Chart -->
        <div class="step-card animate-slide-up" id="metrics-comparison-card" style="margin-bottom: 2rem;">
          <div class="step-header">
            <div class="step-header-left">
              <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>Key Metrics Comparison</h2>
            </div>
            <span class="tooltip-icon">i<span class="tooltip-content">Direct comparison of key metrics.</span></span>
            <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
          </div>
          <div class="card-content">
            <div class="chart-container" style="height: 350px;">
              <canvas id="metrics-comparison-chart"></canvas>
            </div>
          </div>
        </div>
        
        <!-- NEW: Confusion Matrix Comparison -->
        <?php if (isset($result['Ground Truth']) && ($result['Ground Truth'] ?? '') !== 'N/A (no ground truth)'): ?>
        <div class="step-card animate-slide-up" id="confusion-matrix-card" style="margin-bottom: 2rem;">
          <div class="step-header">
            <div class="step-header-left">
              <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.5-6h15m-15-6h15" /></svg>Confusion Matrix</h2>
            </div>
            <span class="tooltip-icon">i<span class="tooltip-content">Visualizes the model's performance against the Ground Truth. (TP=True Positive, FN=False Negative, etc.)</span></span>
            <!-- UPDATED: Added maximize button -->
            <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
          </div>
          <div class="card-content">
            <!-- UPDATED: Re-introduced comparison-grid wrapper -->
            <div class="comparison-grid">
              
              <!-- WOA Matrix -->
              <div class="confusion-matrix-wrapper">
                <h3>Standard WOA</h3>
                <!-- UPDATED: New table-based matrix -->
                <div class="matrix-container">
                  <span class="matrix-label-y">Predicted</span>
                  <span class="matrix-label-x">Actual</span>
                  <table class="matrix-table">
                    <thead>
                      <tr>
                        <th></th>
                        <th>Positive</th>
                        <th>Negative</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <th>Positive</th>
                        <td class="matrix-cell <?= $woa_outcome === 'TP' ? 'is-active matrix-tp' : '' ?>">
                          <span class="matrix-value">TP</span>
                          <span class="matrix-label">True Positive</span>
                        </td>
                        <td class="matrix-cell <?= $woa_outcome === 'FP' ? 'is-active matrix-fp' : '' ?>">
                          <span class="matrix-value">FP</span>
                          <span class="matrix-label">False Positive</span>
                        </td>
                      </tr>
                      <tr>
                        <th>Negative</th>
                        <td class="matrix-cell <?= $woa_outcome === 'FN' ? 'is-active matrix-fn' : '' ?>">
                          <span class="matrix-value">FN</span>
                          <span class="matrix-label">False Negative</span>
                        </td>
                        <td class="matrix-cell <?= $woa_outcome === 'TN' ? 'is-active matrix-tn' : '' ?>">
                          <span class="matrix-value">TN</span>
                          <span class="matrix-label">True Negative</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- EWOA Matrix -->
              <div class="confusion-matrix-wrapper ewoa-card">
                <h3>Enhanced WOA</h3>
                <!-- UPDATED: New table-based matrix -->
                <div class="matrix-container">
                  <span class="matrix-label-y">Predicted</span>
                  <span class="matrix-label-x">Actual</span>
                  <table class="matrix-table">
                    <thead>
                      <tr>
                        <th></th>
                        <th>Positive</th>
                        <th>Negative</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <th>Positive</th>
                        <td class="matrix-cell <?= $ewoa_outcome === 'TP' ? 'is-active matrix-tp' : '' ?>">
                          <span class="matrix-value">TP</span>
                          <span class="matrix-label">True Positive</span>
                        </td>
                        <td class="matrix-cell <?= $ewoa_outcome === 'FP' ? 'is-active matrix-fp' : '' ?>">
                          <span class="matrix-value">FP</span>
                          <span class="matrix-label">False Positive</span>
                        </td>
                      </tr>
                      <tr>
                        <th>Negative</th>
                        <td class="matrix-cell <?= $ewoa_outcome === 'FN' ? 'is-active matrix-fn' : '' ?>">
                          <span class="matrix-value">FN</span>
                          <span class="matrix-label">False Negative</span>
                        </td>
                        <td class="matrix-cell <?= $ewoa_outcome === 'TN' ? 'is-active matrix-tn' : '' ?>">
                          <span class="matrix-value">TN</span>
                          <span class="matrix-label">True Negative</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

            </div>
          </div>
        </div>
        <?php endif; ?> <!-- End check for Ground Truth -->


        <!-- NEW: Top Feature Contributors (side-by-side) -->
        <div class="comparison-grid">

          <!-- WOA TFC Card -->
          <div class="step-card animate-slide-up" id="woa-tfc-card">
            <div class="step-header">
              <div class="step-header-left">
                <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.31h5.518a.562.562 0 01.31.95l-4.203 3.03a.563.563 0 00-.182.635l1.578 4.87a.562.562 0 01-.84.61l-4.72-3.47a.563.563 0 00-.652 0l-4.72 3.47a.562.562 0 01-.84-.61l1.578-4.87a.563.563 0 00-.182-.635L2.543 9.87a.562.562 0 01.31-.95h5.518a.563.563 0 00.475-.31L11.48 3.5z"/></svg>WOA Contributors</h2>
              </div>
              <span class="tooltip-icon">i<span class="tooltip-content">Top features selected by Standard WOA.</span></span>
              <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.T5.5 0 0 1 .5-.5z"/></svg></button>
            </div>
            <div class="card-content">
              <!-- UPDATED: Removed tfc-layout-container -->
              <div class="tfc-table-wrapper">
                <div class="table-wrapper-scroll" id="woa-tfc-table-scroll" style="max-height: 260px;">
                  <table class="data-table">
                    <thead><tr><th>Feature</th></tr></thead> <!-- UPDATED: Header -->
                    <tbody id="woa-tfc-table-body"></tbody>
                  </table>
                </div>
              </div>
              <!-- UPDATED: Chart wrapper removed -->
            </div>
          </div>

          <!-- EWOA TFC Card -->
          <div class="step-card animate-slide-up ewoa-card" id="ewoa-tfc-card">
            <div class="step-header">
              <div class="step-header-left">
                <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.31h5.518a.562.562 0 01.31.95l-4.203 3.03a.563.563 0 00-.182.635l1.578 4.87a.562.562 0 01-.84.61l-4.72-3.47a.563.563 0 00-.652 0l-4.72 3.47a.562.562 0 01-.84-.61l1.578-4.87a.563.563 0 00-.182-.635L2.543 9.87a.562.562 0 01.31-.95h5.518a.563.563 0 00.475-.31L11.48 3.5z"/></svg>EWOA Contributors</h2>
              </div>
              <span class="tooltip-icon">i<span class="tooltip-content">Top features selected by Enhanced WOA.</span></span>
              <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg></button>
            </div>
            <div class="card-content">
              <!-- UPDATED: Removed tfc-layout-container -->
              <div class="tfc-table-wrapper">
                <div class="table-wrapper-scroll" id="ewoa-tfc-table-scroll" style="max-height: 260px;">
                  <table class="data-table">
                    <thead><tr><th>Feature</th></tr></thead> <!-- UPDATED: Header -->
                    <tbody id="ewoa-tfc-table-body"></tbody>
                  </table>
                </div>
              </div>
              <!-- UPDATED: Chart wrapper removed -->
            </div>
          </div>
        </div>

      </div>
    </div> <!-- /results-wrapper -->
  </div> <!-- /main-container -->

  <!-- Modal -->
  <div id="card-modal-overlay">
    <div id="card-modal-content">
      <button class="close-modal-btn">&times;</button>
      <h2 class="modal-title">Modal Title</h2>
      <div class="modal-body" id="card-modal-body"></div>
    </div>
  </div>

  <!-- Full-screen loader REMOVED -->

  <footer><p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p></footer>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    // === Refs ===
    const form=document.getElementById('comparison-form'),
          fileInput=document.getElementById('image-upload'),
          uploadArea=document.getElementById('upload-area'),
          previewWrapper=document.getElementById('image-preview-wrapper'),
          previewImg=document.getElementById('image-preview'),
          fileMetaText=document.getElementById('file-meta-text'),
          runButton=document.getElementById('run-comparison-btn'),
          btnText=document.getElementById('btn-text'),
          resetButton=document.getElementById('reset-btn'),
          // NEW: Loader refs
          skeletonLoader=document.getElementById('skeleton-loader'),
          resultsWrapper=document.getElementById('results-wrapper'),
          placeholderCard=document.getElementById('comparison-placeholder'),
          resultsContainer=document.getElementById('comparison-results'),
          errorContainer=document.querySelector('.error-card'), // Find error card if it exists
          // Modal refs
          modalOverlay=document.getElementById('card-modal-overlay'),
          modalContent=document.getElementById('card-modal-content'),
          modalTitle=modalContent.querySelector('.modal-title'),
          modalBody=modalContent.querySelector('#card-modal-body'),
          closeModalBtn=modalContent.querySelector('.close-modal-btn');

    // === State ===
    let activeCharts = {};
    const PRETTY_NAMES = <?php echo json_encode($pretty_names); ?> || {};
    const computedStyles = getComputedStyle(document.documentElement);
    const chartColors = { 
         accentGlow: computedStyles.getPropertyValue('--accent-glow').trim(), 
      	accentGlowTint: computedStyles.getPropertyValue('--accent-glow-tint').trim(), 
      	accentSuccess: computedStyles.getPropertyValue('--accent-success').trim(), 
      	accentWarning: computedStyles.getPropertyValue('--accent-warning').trim(), 
      	textDark: computedStyles.getPropertyValue('--text-dark').trim(), 
        textHeader: computedStyles.getPropertyValue('--text-header').trim(),
      	borderColor: computedStyles.getPropertyValue('--border-color').trim(), 
      	bgDark: computedStyles.getPropertyValue('--bg-dark').trim(),
        pastels: ['rgba(99, 179, 237, 0.7)','rgba(132, 204, 145, 0.7)','rgba(250, 202, 154, 0.7)','rgba(196, 181, 253, 0.7)','rgba(252, 165, 165, 0.7)','rgba(153, 246, 228, 0.7)']
    };

    // === File Handling ===
    function handleFile(f){
      if(f && f.type.startsWith('image/')){
        const r=new FileReader();
        r.onload=e=>{
          previewImg.src=e.target.result;
          previewWrapper.style.display='flex';
          uploadArea.style.display='none';
          runButton.disabled=false;
          resetButton.disabled=false;
          btnText.textContent = 'Run Comparison';
        };
        r.readAsDataURL(f);
        fileMetaText.textContent=`${f.name} (${(f.size/1024).toFixed(1)} KB)`;
        fileMetaText.style.display='block';
      }
    }
    fileInput.addEventListener('change',e=>handleFile(e.target.files[0]));
    ['dragenter','dragover','dragleave','drop'].forEach(n=>{uploadArea.addEventListener(n,e=>{e.preventDefault();e.stopPropagation()},!1)});
    ['dragenter','dragover'].forEach(n=>{uploadArea.addEventListener(n,()=>uploadArea.classList.add('dragover'),!1)});
    ['dragleave','drop'].forEach(n=>{uploadArea.addEventListener(n,()=>uploadArea.classList.remove('dragover'),!1)});
    uploadArea.addEventListener('drop',e=>{const d=e.dataTransfer;const f=d.files[0];fileInput.files=d.files;handleFile(f)},!1);

    // === NEW: Form Submission & Loader Logic ===
    form.addEventListener('submit',e=>{
      const s=e.submitter||document.activeElement;
      if(s && s.id==='run-comparison-btn'){
        if(fileInput.files.length > 0 || previewWrapper.style.display==='flex'){
          // Show skeleton loader, hide other states
          skeletonLoader.style.display = 'block';
          resultsContainer.style.display = 'none';
          placeholderCard.style.display = 'none';
          if (errorContainer) errorContainer.style.display = 'none';
          
          btnText.textContent = 'Analyzing...';
          runButton.disabled = true;
          resetButton.disabled = true;
        } else {
          e.preventDefault(); // Don't submit if no file
        }
      }
      // Reset action will trigger a normal form submission and page reload
    });
    
    // Logic to restore button state on page load (e.g., after POST-Redirect)
    if(previewWrapper.style.display==='flex') {
        runButton.disabled = false;
        btnText.textContent = 'Re-run Comparison';
        fileMetaText.textContent = 'Previously uploaded image.';
        fileMetaText.style.display = 'block';
    }

    // === Destroy Charts ===
    function destroyAllCharts() {
        Object.values(activeCharts).forEach(chart => {
            if (chart) chart.destroy();
        });
        activeCharts = {};
    }

    // === UPDATED: Render TFC (Table Only) ===
    function renderTFC(containerId, tableBodyId, features, chartLabel) {
        const tfc = Array.isArray(features) ? features : [];
        
        // --- Populate Table ---
        const tableBody = document.getElementById(tableBodyId);
        if (tableBody) {
            // Create rows based on simple string list
            const rows = tfc.map((name, index) => {
                const prettyName = PRETTY_NAMES[name] || name;
                return `<tr>
                          <td>${escapeHTML(prettyName)}</td>
                        </tr>`;
            }).join('') || '<tr><td>No features found</td></tr>';
            tableBody.innerHTML = rows;
        }

        // --- Chart and Height Sync Logic REMOVED ---
    }
    
    // === UPDATED: Render Grouped Bar Chart ===
    function renderMetricsChart(result) {
        const cv = document.getElementById('metrics-comparison-chart');
        if (!cv) return;
        if (activeCharts['metrics-comparison-chart']) activeCharts['metrics-comparison-chart'].destroy();
        
        // UPDATED: Data now includes Confidence and Exec. Time
        const woaData = [
            Number(result.WOA.Confidence || 0),
            Number(result.WOA['Execution Time'] || 0)
        ];
        const ewoaData = [
            Number(result.EWOA.Confidence || 0),
            Number(result.EWOA['Execution Time'] || 0)
        ];

        activeCharts['metrics-comparison-chart'] = new Chart(cv.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Confidence', 'Exec. Time (s)'], // UPDATED: Labels
                datasets: [
                    {
                        label: 'WOA',
                        data: woaData,
                        backgroundColor: 'rgba(127,140,141,0.7)', // --text-dark
                        borderColor: 'rgba(127,140,141,1)',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: 'EWOA',
                        data: ewoaData,
                        backgroundColor: 'rgba(216, 27, 96, 0.7)', // --accent-glow
                        borderColor: 'rgba(216, 27, 96, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, grid: { color: chartColors.borderColor } }
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(4)}`
                        }
                    }
                }
            }
        });
    }

    // === Render Results (if data exists on load) ===
    <?php if ($result): ?>
    try {
        destroyAllCharts(); // Clear any old charts
        const resultData = <?php echo json_encode($result); ?>;
        
        // 1. Render Key Metrics Chart
        renderMetricsChart(resultData);

        // 2. Render WOA TFC Table
        renderTFC('woa-tfc', 'woa-tfc-table-body', resultData.WOA['Top Features'], 'WOA Contributions');

        // 3. Render EWOA TFC Table
        renderTFC('ewoa-tfc', 'ewoa-tfc-table-body', resultData.EWOA['Top Features'], 'EWOA Contributions');

        // 4. Confusion Matrix render call REMOVED (now static HTML)

    } catch(e){ console.error('Failed to render charts on load:', e); }
    <?php endif; ?>

    // === Modal Logic ===
    function openModal(title, contentHtml, chartId = null) {
      modalTitle.textContent = title;
      modalBody.innerHTML = contentHtml;
      modalOverlay.classList.add('visible');

      // UPDATED: Check if chartId is for a chart that still exists
      if (chartId && chartId === 'metrics-comparison-chart' && activeCharts[chartId]) {
        // Find the new canvas inside the modal
        const modalCanvas = modalBody.querySelector('canvas');
        if (modalCanvas) {
            const chartConfig = activeCharts[chartId].config;
            let chartHeight = '400px'; // Default modal height
            modalCanvas.parentElement.style.height = chartHeight;
            
            // Re-create the chart in the modal
            activeCharts['modal_instance'] = new Chart(modalCanvas.getContext('2d'), chartConfig);
        }
      }
    }

    function closeCardModal() {
        modalOverlay.classList.remove('visible');
        if (activeCharts['modal_instance']) {
            activeCharts['modal_instance'].destroy();
            delete activeCharts['modal_instance'];
        }
    }
    
    closeModalBtn.addEventListener('click', closeCardModal);
    modalOverlay.addEventListener('click',e=>{ if(e.target===modalOverlay) closeCardModal(); });
    
    // Delegated click listener for maximize buttons
    document.getElementById('comparison-results').addEventListener('click', e => {
        const btn = e.target.closest('.maximize-card-btn');
        if (!btn) return;
        
        const card = btn.closest('.step-card');
        if (!card) return;

        const title = card.querySelector('h2')?.textContent.trim() || 'Details';
        const content = card.querySelector('.card-content');
        const canvas = card.querySelector('canvas');
        // UPDATED: Check if canvas exists before getting id
        const chartId = canvas ? canvas.id : null; 
        
        if (content) {
            // Don't pass chartId if it's null
            openModal(title, content.innerHTML, chartId);
        }
    });

    // === Details Toggle ===
    document.addEventListener('click', (event) => {
      const button = event.target.closest('.details-toggle-btn');
      if (!button) return;
      const targetSelector = button.dataset.target;
      const content = document.querySelector(targetSelector);
      if (!content) return;

      button.classList.toggle('active');
      if (content.style.maxHeight && content.style.maxHeight !== '0px') {
        content.style.maxHeight = null;
        button.textContent = button.textContent.replace('Hide', 'Show');
      } else {
        content.style.maxHeight = '1000px'; // Use a fixed large value
        button.textContent = button.textContent.replace('Show', 'Hide');
      }
    });

    // === Utility ===
    function escapeHTML(s) { return String(s ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;'); }
  });
  </script>
</body>
</html>

