<?php

ini_set('memory_limit', '2048M');
// Set error logging to a file
ini_set('error_log', '/path/to/php_errors.log');

// Enable error reporting and display errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';

use Phpml\Regression\LeastSquares;
use Phpml\ModelManager;

// Path to the CSV file
$csvFilePath = 'Training_data/cleaned_training_data.csv';

// ── Pass 1: aggregate to one daily min/max row per (date, site) ──────────────
$file = fopen($csvFilePath, 'r');
fgetcsv($file, 0, ',', '"', '\\'); // skip header

$maxHumidity    = [];
$minHumidity    = [];
$maxTemperature = [];
$minTemperature = [];

while (($row = fgetcsv($file, 0, ',', '"', '\\')) !== false) {
    $date    = date('Y-m-d', strtotime($row[1]));
    $site_id = $row[0];
    $humidity    = (float)$row[2];
    $temperature = (float)$row[3];
    if ($temperature == 0) continue;

    $k = $date . '|' . $site_id;
    if (!isset($maxHumidity[$k])) {
        $maxHumidity[$k]    = $humidity;
        $minHumidity[$k]    = $humidity;
        $maxTemperature[$k] = $temperature;
        $minTemperature[$k] = $temperature;
    } else {
        $maxHumidity[$k]    = max($maxHumidity[$k], $humidity);
        $minHumidity[$k]    = min($minHumidity[$k], $humidity);
        $maxTemperature[$k] = max($maxTemperature[$k], $temperature);
        $minTemperature[$k] = min($minTemperature[$k], $temperature);
    }
}
fclose($file);

// ── Pass 2: build one sample per (date, site) ─────────────────────────────────
$samples              = [];
$targetsMaxHumidity   = [];
$targetsMinHumidity   = [];
$targetsMaxTemperature = [];
$targetsMinTemperature = [];

foreach ($maxHumidity as $k => $maxH) {
    [$date, $site_id] = explode('|', $k);
    $ts      = strtotime($date);
    $doy     = (int)date('z', $ts) + 1;
    $sinDoy  = sin(2 * M_PI * $doy / 365);
    $cosDoy  = cos(2 * M_PI * $doy / 365);
    $month   = (int)date('n', $ts);
    $sinMon  = sin(2 * M_PI * $month / 12);
    $cosMon  = cos(2 * M_PI * $month / 12);
    $siteInt = (int)$site_id;

    // Use N-1 dummies (drop site 5 as reference) to avoid perfect multicollinearity
    $d1 = ($siteInt === 1) ? 1 : 0;
    $d2 = ($siteInt === 2) ? 1 : 0;
    $d3 = ($siteInt === 3) ? 1 : 0;
    $d4 = ($siteInt === 4) ? 1 : 0;

    // Site × season interactions so each site gets its own seasonal curve
    $samples[] = [
        $sinDoy, $cosDoy, $sinMon, $cosMon,
        $d1, $d2, $d3, $d4,
        $d1 * $sinDoy, $d1 * $cosDoy,
        $d2 * $sinDoy, $d2 * $cosDoy,
        $d3 * $sinDoy, $d3 * $cosDoy,
        $d4 * $sinDoy, $d4 * $cosDoy,
    ];
    $targetsMaxHumidity[]    = $maxH;
    $targetsMinHumidity[]    = $minHumidity[$k];
    $targetsMaxTemperature[] = $maxTemperature[$k];
    $targetsMinTemperature[] = $minTemperature[$k];
}

echo "Training on " . count($samples) . " aggregated daily samples.\n";

// Train four separate models: maximum and minimum humidity, maximum and minimum temperature

// Initialize LeastSquares for maximum humidity regression
$maxLinearHumidity = new LeastSquares();
$maxLinearHumidity->train($samples, $targetsMaxHumidity);

// Initialize LeastSquares for minimum humidity regression
$minLinearHumidity = new LeastSquares();
$minLinearHumidity->train($samples, $targetsMinHumidity);

// Initialize LeastSquares for maximum temperature regression
$maxLinearTemperature = new LeastSquares();
$maxLinearTemperature->train($samples, $targetsMaxTemperature);

// Initialize LeastSquares for minimum temperature regression
$minLinearTemperature = new LeastSquares();
$minLinearTemperature->train($samples, $targetsMinTemperature);

// Save the trained models
$modelManager = new ModelManager();
$modelManager->saveToFile($maxLinearHumidity, 'Saved_models/max_humidity_model.phpml');
$modelManager->saveToFile($minLinearHumidity, 'Saved_models/min_humidity_model.phpml');
$modelManager->saveToFile($maxLinearTemperature, 'Saved_models/max_temperature_model.phpml');
$modelManager->saveToFile($minLinearTemperature, 'Saved_models/min_temperature_model.phpml');

echo "Models trained and saved successfully.\n";
