<?php

ini_set('memory_limit', '2048M');

require_once __DIR__ . '/vendor/autoload.php';

use Phpml\ModelManager;
use Phpml\Regression\LeastSquares;

// Function to generate predictions for a specific site number and date
function generateMinMaxPredictions($specificSiteNumber, $specificDate)
{
    // Mapping of site numbers to location names
    $locationNames = [
        1 => 'Wynyard',
        2 => 'Launceston',
        3 => 'Smithton',
        4 => 'Hobart',
        5 => 'Campania',
    ];

    // Load the trained models
    $modelManager = new ModelManager();
    $maxHumidityModel = $modelManager->restoreFromFile('Saved_models/max_humidity_model.phpml');
    $minHumidityModel = $modelManager->restoreFromFile('Saved_models/min_humidity_model.phpml');
    $maxTemperatureModel = $modelManager->restoreFromFile('Saved_models/max_temperature_model.phpml');
    $minTemperatureModel = $modelManager->restoreFromFile('Saved_models/min_temperature_model.phpml');

    // Build the same feature vector used during training
    $timestamp = strtotime($specificDate);
    $doy       = (int)date('z', $timestamp) + 1;
    $sinDoy    = sin(2 * M_PI * $doy / 365);
    $cosDoy    = cos(2 * M_PI * $doy / 365);
    $month     = (int)date('n', $timestamp);
    $sinMon    = sin(2 * M_PI * $month / 12);
    $cosMon    = cos(2 * M_PI * $month / 12);
    $siteInt   = (int)$specificSiteNumber;

    // N-1 dummies (site 5 is reference) — matches training
    $d1 = ($siteInt === 1) ? 1 : 0;
    $d2 = ($siteInt === 2) ? 1 : 0;
    $d3 = ($siteInt === 3) ? 1 : 0;
    $d4 = ($siteInt === 4) ? 1 : 0;

    $features = [
        $sinDoy, $cosDoy, $sinMon, $cosMon,
        $d1, $d2, $d3, $d4,
        $d1 * $sinDoy, $d1 * $cosDoy,
        $d2 * $sinDoy, $d2 * $cosDoy,
        $d3 * $sinDoy, $d3 * $cosDoy,
        $d4 * $sinDoy, $d4 * $cosDoy,
    ];

    // Predict maximum and minimum humidity and temperature
    $maxHumidityPrediction    = $maxHumidityModel->predict([$features]);
    $minHumidityPrediction    = $minHumidityModel->predict([$features]);
    $maxTemperaturePrediction = $maxTemperatureModel->predict([$features]);
    $minTemperaturePrediction = $minTemperatureModel->predict([$features]);

    $clampH = fn($v) => round(max(0.0, min(100.0, $v)), 1);

    // Format the predictions and return
    return [
        'site_name' => $locationNames[$specificSiteNumber],
        'timestamp' => date('Y-m-d', $timestamp),
        'minTemperaturePrediction' => round($minTemperaturePrediction[0], 1),
        'maxTemperaturePrediction' => round($maxTemperaturePrediction[0], 1),
        'minHumidityPrediction' => $clampH($minHumidityPrediction[0]),
        'maxHumidityPrediction' => $clampH($maxHumidityPrediction[0]),
    ];
}
