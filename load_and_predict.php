<?php

ini_set('memory_limit', '2048M');

require_once __DIR__ . '/vendor/autoload.php';

use Phpml\ModelManager;
use Phpml\Regression\LeastSquares;

function weatherPredictionModels(): array
{
    static $models = null;

    if ($models === null) {
        $modelManager = new ModelManager();
        $models = [
            'maxHumidity' => $modelManager->restoreFromFile(__DIR__ . '/Saved_models/max_humidity_model.phpml'),
            'minHumidity' => $modelManager->restoreFromFile(__DIR__ . '/Saved_models/min_humidity_model.phpml'),
            'maxTemperature' => $modelManager->restoreFromFile(__DIR__ . '/Saved_models/max_temperature_model.phpml'),
            'minTemperature' => $modelManager->restoreFromFile(__DIR__ . '/Saved_models/min_temperature_model.phpml'),
        ];
    }

    return $models;
}

function weatherConditionIcon(float $minTemp, float $maxTemp, float $maxHumidity): array
{
    if ($maxHumidity >= 86) {
        return ['icon' => 'bi-cloud-rain-heavy-fill', 'label' => 'Wet conditions likely'];
    }
    if ($maxHumidity >= 76) {
        return ['icon' => 'bi-cloud-drizzle-fill', 'label' => 'Humid conditions likely'];
    }
    if ($maxTemp <= 10) {
        return ['icon' => 'bi-cloud-fill', 'label' => 'Cool and cloudy'];
    }
    if ($maxTemp >= 22 && $maxHumidity < 65) {
        return ['icon' => 'bi-sun-fill', 'label' => 'Warmer and clearer'];
    }

    return ['icon' => 'bi-cloud-sun-fill', 'label' => 'Mild conditions'];
}

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

    $models = weatherPredictionModels();

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
    $maxHumidityPrediction    = $models['maxHumidity']->predict([$features]);
    $minHumidityPrediction    = $models['minHumidity']->predict([$features]);
    $maxTemperaturePrediction = $models['maxTemperature']->predict([$features]);
    $minTemperaturePrediction = $models['minTemperature']->predict([$features]);

    $clampH = fn($v) => round(max(0.0, min(100.0, $v)), 1);
    $minTemp = round(min($minTemperaturePrediction[0], $maxTemperaturePrediction[0]), 1);
    $maxTemp = round(max($minTemperaturePrediction[0], $maxTemperaturePrediction[0]), 1);
    $minHumidity = $clampH(min($minHumidityPrediction[0], $maxHumidityPrediction[0]));
    $maxHumidity = $clampH(max($minHumidityPrediction[0], $maxHumidityPrediction[0]));

    // Format the predictions and return
    return [
        'site_name' => $locationNames[$specificSiteNumber],
        'timestamp' => date('Y-m-d', $timestamp),
        'minTemperaturePrediction' => $minTemp,
        'maxTemperaturePrediction' => $maxTemp,
        'minHumidityPrediction' => $minHumidity,
        'maxHumidityPrediction' => $maxHumidity,
    ];
}

function generateMultiDayForecast(int $siteId, string $startDate, int $days = 10): array
{
    $start = new DateTimeImmutable($startDate);
    $forecast = [];

    for ($offset = 0; $offset < $days; $offset++) {
        $date = $start->modify("+{$offset} days");
        $prediction = generateMinMaxPredictions($siteId, $date->format('Y-m-d'));
        $condition = weatherConditionIcon(
            (float)$prediction['minTemperaturePrediction'],
            (float)$prediction['maxTemperaturePrediction'],
            (float)$prediction['maxHumidityPrediction']
        );

        $forecast[] = array_merge($prediction, $condition, [
            'dayLabel' => $date->format('D'),
            'dateLabel' => $date->format('j M'),
        ]);
    }

    return $forecast;
}
