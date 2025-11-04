<?php

// Allow long-running benchmark without timing out
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ignore_user_abort(true);
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }

$config = require __DIR__ . '/config.php';

$results = null;
$error_message = null;
$loading_message = null;
// Fixed benchmark parameters (no UI overrides)
$bench = [
    'algo' => 'both',
    'pop' => 30,
    'iters' => 500,
    'runs' => 30,
    'dim' => 30,
];

// 1. CHECK FOR FORM SUBMISSION
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $loading_message = "Benchmark is running... this may take several minutes. Please do not close this page.";

    // 2. USE FIXED CONFIGURATION
    $pop = $bench['pop'];
    $iters = $bench['iters'];
    $runs = $bench['runs'];
    $dim = $bench['dim'];
    $seed = rand(1, 100000); // New seed for each independent run request

    // 3. CONSTRUCT PYTHON COMMAND (Windows/XAMPP friendly)
    $python = $config['python_path'] ?? null; // e.g., C:\\Python39\\python.exe
    $workdir = $config['workdir'] ?? null;    // e.g., C:\\xampp\\htdocs\\CUR_TOOL\\WOA-TOOL

    if (!$python || !$workdir) {
        $error_message = "Config error: 'python_path' or 'workdir' is missing in config.php.";
    } else {
        // Try multiple potential benchmarking entry points
        $entryPoints = [
            'woa_tool.cli bench',            // original guess (may not exist)
            'woa_tool.bench',                // possible module
            'woa_tool.benchmark',            // alternative name
            'woa_tool.tools.bench'           // nested tools path
        ];

        $lastStdout = '';
        $lastStderr = '';
        $lastCode = -1;
        $jsonErr = '';
        $found = false;

        foreach ($entryPoints as $entry) {
            $cmd = sprintf(
                'cd /d %s && %s -m %s --functions rosenbrock griewank --algo both --pop %d --iters %d --runs %d --seed %d --dim %d',
                escapeshellarg($workdir),
                escapeshellcmd($python),
                $entry,
                $pop,
                $iters,
                $runs,
                $seed,
                $dim
            );

            $descriptorspec = [ 0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'] ];
            $process = proc_open($cmd, $descriptorspec, $pipes, $workdir);
            if (!is_resource($process)) { continue; }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $code = proc_close($process);

            $decoded_output = json_decode(trim($stdout), true, 512, JSON_BIGINT_AS_STRING);
            if ($code === 0 && json_last_error() === JSON_ERROR_NONE) {
                $results = $decoded_output;
                $found = true;
                break;
            }

            $lastStdout = $stdout;
            $lastStderr = $stderr;
            $lastCode = $code;
            $jsonErr = json_last_error() === JSON_ERROR_NONE ? 'none' : json_last_error_msg();
        }

        if (!$found) {
            $error_message = "Benchmark entry point not found in installed Python package. The CLI you have does not support 'bench'.\n";
            $error_message .= "Tried modules: " . htmlspecialchars(implode(', ', $entryPoints)) . ".\n";
            $error_message .= "Exit code: {$lastCode}. JSON error: {$jsonErr}.";
            if (!empty($lastStderr)) { $error_message .= "<br><strong>Stderr (last attempt):</strong><pre>" . htmlspecialchars($lastStderr) . "</pre>"; }
            if (!empty($lastStdout)) { $error_message .= "<br><strong>Stdout (last attempt):</strong><pre>" . htmlspecialchars($lastStdout) . "</pre>"; }
            $error_message .= "<br>Fix: Update the Python package to a version that includes benchmarking, or provide a benchmark runner module (e.g., woa_tool.bench) that outputs JSON.";
        }
    }
    
    // Clear loading message after execution
    $loading_message = null;
}

/**
 * Helper function to format metric names
 */
function format_metric_name($key) {
    $names = [
        'best_mean' => 'Mean Best Fitness',
        'best_std' => 'Std Dev (Fitness)',
        'average_eer' => 'Mean EER (0-1)',
        'runtime_s' => 'Mean Runtime (s)',
        'convergence_rate_mean' => 'Mean Convergence Rate',
        'convergence_rate_std' => 'Std Dev (Conv. Rate)'
    ];
    return $names[$key] ?? ucwords(str_replace('_', ' ', $key));
}

function is_negative_zero(float $v): bool {
    if ($v != 0.0) return false;
    $q = fdiv(1.0, $v);
    return is_infinite($q) && $q < 0.0;
}

function format_num($value, int $decimals = 6): string {
    if ($value === null) return '—';

    // If it arrived as a string and is an integer literal, print exactly as-is
    if (is_string($value)) {
        if (preg_match('/^-?\d+$/', $value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        // Otherwise keep as-is or fall through to float formatting if desired:
        // return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // $value = (float)$value; // enable if you want fixed precision for decimals
    }

    if (is_int($value)) {
        // Exact, no thousands separators
        return (string)$value;
    }

    if (!is_numeric($value)) {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // Float formatting (preserve -0.0 and avoid number_format)
    $v = (float)$value;
    if (!is_finite($v)) return is_nan($v) ? 'NaN' : ($v > 0 ? '∞' : '-∞');

    $abs = abs($v);
    $small = pow(10, -$decimals);

    if ($abs < $small) {
        // show signed zero with chosen precision
        return (is_negative_zero($v) || $v < 0 ? '-' : '') . sprintf('%.' . $decimals . 'f', 0.0);
    }

    if ($abs >= 1e6) {
        // Large floats → scientific
        return sprintf('%.' . $decimals . 'e', $v);
    }

    return sprintf('%.' . $decimals . 'f', $v);
}

/**
 * Helper function to format statistical significance
 */
function format_significance($p_value, $alpha = 0.05) {
    if ($p_value < $alpha) {
        return "<strong class='text-green-600'>Statistically Significant (p < " . $alpha . ")</strong>";
    } else {
        return "<strong class='text-yellow-600'>Not Statistically Significant (p >= " . $alpha . ")</strong>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1" />
    <title>Benchmark | WOA-TOOL</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐳</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=27">
</head>
<body>

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
                <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">Feature Detection</a>
                <a href="benchmark_backend.php" class="<?= basename($_SERVER['PHP_SELF']) == 'benchmark_backend.php' ? 'active' : '' ?>">Benchmark Functions</a>
                <a href="comparison.php" class="<?= basename($_SERVER['PHP_SELF']) == 'comparison.php' ? 'active' : '' ?>">Comparison</a>
            </nav>
        </div>
    </header>

    <div id="aurora-background"></div>

    <div class="main-container">

        <header class="header">
            <h1>
                <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C10.14 2 8.5 3.65 8.5 5.5C8.5 6.4 8.89 7.2 9.5 7.82C7.03 8.35 5.3 10.13 5.3 12.39C5.3 13.53 5.79 14.58 6.6 15.35C5.59 16.32 5 17.58 5 19C5 21.21 6.79 23 9 23C10.86 23 12.5 21.35 12.5 19.5C12.5 18.6 12.11 17.8 11.5 17.18C13.97 16.65 15.7 14.87 15.7 12.61C15.7 11.47 15.21 10.42 14.4 9.65C15.41 8.68 16 7.42 16 6C16 3.79 14.21 2 12 2M12 4C13.1 4 14 4.9 14 6C14 7.03 13.2 7.9 12.18 7.97C12.12 7.99 12.06 8 12 8C10.9 8 10 7.1 10 6C10 4.9 10.9 4 12 4M9 21C7.9 21 7 20.1 7 19C7 17.97 7.8 17.1 8.82 17.03C8.88 17.01 8.94 17 9 17C10.1 17 11 17.9 11 19C11 20.1 10.1 21 9 21" /></svg>
                WOA Benchmark Dashboard
            </h1>
            <div class="quick-guide">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                    Quick Notes
                </h3>
                <ul>
                    <li><strong>Defaults</strong>: both algorithms, 500 iters, 30 pop, dim 30, 30 runs.</li>
                    <li><strong>Run</strong> the benchmark; results render below.</li>
                    <li>If backend missing, a helpful error will appear.</li>
                </ul>
            </div>
        </header>

        <div class="step-card">
            <div class="step-header">
                <div class="step-header-left">
                    <div class="step-number">1</div>
                    <h2>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75L16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z"/></svg>
                        Run Benchmark
                    </h2>
                </div>
            </div>
            <div class="card-content">
            
            <form id="benchmark-form" method="POST" action="benchmark_backend.php">
                <div class="mt-2 text-right">
                    <button id="run-button" type="submit" class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Run Benchmark
                    </button>
                </div>
            </form>

            <?php if ($loading_message): ?>
                <div id="loading-indicator" class="step-card error-card" style="margin-top:1rem;">
                    <strong>Info:</strong> <?php echo $loading_message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="step-card error-card" style="margin-top:1rem;">
                    <strong>Error:</strong> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            </div>
        </div>

        <!-- Results Section -->
        <?php if ($results): ?>
        <div id="results" class="step-card">
            <div class="step-header">
                <div class="step-header-left">
                    <div class="step-number">2</div>
                    <h2>Experiment Log Sheet</h2>
                </div>
            </div>
            <div class="card-content">

            <?php foreach ($results as $func_name => $data): ?>
            <div class="mb-12">
                <h3 class="text-3xl font-bold text-gray-800 mb-4 capitalize"><?php echo htmlspecialchars($func_name); ?> Function</h3>
                
                <!-- Overall Comparison Table -->
                <h4 class="text-xl font-semibold text-gray-700 mb-3">Overall Comparison (<?php echo $bench['runs']; ?> Runs)</h4>
                <div class="table-wrapper-scroll" style="max-height:unset;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>WOA (Standard)</th>
                                <th>EWOA (Enhanced)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Define keys to display in order
                            $metrics_to_show = ['best_mean', 'best_std', 'average_eer', 'runtime_s', 'convergence_rate_mean', 'convergence_rate_std'];
                            foreach ($metrics_to_show as $key): 
                                if (isset($data['woa'][$key])):
                            ?>
                            <tr>
                                <td><?php echo format_metric_name($key); ?></td>
                                <td class="mono"><?php echo format_num($data['woa'][$key], 6); ?></td>
                                <td class="mono"><?php echo format_num($data['ewoa'][$key], 6); ?></td>
                            </tr>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Statistical Test -->
                <?php if (isset($data['wilcoxon']) && $data['wilcoxon']): ?>
                <div class="step-card" style="margin-top:1rem;">
                    <div class="card-content">
                        <h3 style="margin-top:0;">Wilcoxon Signed-Rank Test (on Best Fitness)</h3>
                        <p class="file-meta">Compares the paired fitness values from WOA and EWOA.</p>
                        <p class="mono" style="margin-top:.5rem;">p-value: <code><?php echo format_num($data['wilcoxon']['p_value'], 8); ?></code></p>
                        <p style="margin-top:.25rem;">Result: <?php echo format_significance($data['wilcoxon']['p_value']); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Run Block Summaries -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- WOA Run Blocks -->
                    <div>
                        <h4 class="text-xl font-semibold text-gray-700 mb-3">WOA: Run Block Summary</h4>
                        <div class="table-wrapper-scroll">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Runs</th>
                                        <th>Mean Fitness</th>
                                        <th>Std Dev</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['woa']['run_block_summaries'] as $block): ?>
                                    <tr>
                                        <td><?php echo $block['runs'][0] . '-' . $block['runs'][1]; ?></td>
                                        <td class="mono"><?php echo format_num($block['best_mean'], 6); ?></td>
                                        <td class="mono"><?php echo format_num($block['best_std'], 6); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- EWOA Run Blocks -->
                    <div>
                        <h4 class="text-xl font-semibold text-gray-700 mb-3">EWOA: Run Block Summary</h4>
                        <div class="table-wrapper-scroll">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Runs</th>
                                        <th>Mean Fitness</th>
                                        <th>Std Dev</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['ewoa']['run_block_summaries'] as $block): ?>
                                    <tr>
                                        <td><?php echo $block['runs'][0] . '-' . $block['runs'][1]; ?></td>
                                        <td class="mono"><?php echo format_num($block['best_mean'], 6); ?></td>
                                        <td class="mono"><?php echo format_num($block['best_std'], 6); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Raw Fitness Data -->
                <details class="step-card" style="margin-top:1rem;">
                    <summary class="btn btn-secondary" style="display:inline-flex;">
                        Show/Hide Raw Fitness Values for all <?php echo $bench['runs']; ?> runs
                    </summary>
                    <div class="card-content" style="margin-top:.75rem; display:grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="step-card">
                            <div class="card-content">
                            <h5>WOA Best Fitness Values</h5>
                            <pre class="mono"><?php echo htmlspecialchars(implode("\n", array_map(function($i, $val) {
                                return "Run " . ($i+1) . ": " . format_num($val, 6);
                            }, array_keys($data['woa']['all']), $data['woa']['all']))); ?></pre>
                            </div>
                        </div>
                        <div class="step-card">
                            <div class="card-content">
                            <h5>EWOA Best Fitness Values</h5>
                            <pre class="mono"><?php echo htmlspecialchars(implode("\n", array_map(function($i, $val) {
                                return "Run " . ($i+1) . ": " . format_num($val, 6);
                            }, array_keys($data['ewoa']['all']), $data['ewoa']['all']))); ?></pre>
                            </div>
                        </div>
                    </div>
                </details>

            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif (!$loading_message && !$error_message): ?>
        <div class="step-card text-center">
            <div class="card-content">
                <h2>Ready to Run</h2>
                <p class="file-meta" style="margin-top:.5rem;">Click "Run Benchmark" to execute with defaults (both, 500 iters, 30 pop, dim 30, 30 runs).</p>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('benchmark-form');
            const runButton = document.getElementById('run-button');
            form.addEventListener('submit', function() {
                if (runButton) {
                    runButton.disabled = true;
                    runButton.textContent = 'Running...';
                }
            });
        });
    </script>

</body>
</html>
