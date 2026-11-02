<?php

// Build a compact seasonal cache for the dashboard chart. Run after replacing
// the training CSV; the web application reads this file instead of scanning
// every CSV row for each visitor.

$source = __DIR__ . '/Training_data/cleaned_training_data.csv';
$target = __DIR__ . '/Training_data/dashboard_seasonal_averages.json';
$totals = [];

$handle = fopen($source, 'r');
fgetcsv($handle, 0, ',', '"', '\\');
while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $site = (string)(int)$row[0];
    $timestamp = strtotime($row[1]);
    $temperature = (float)$row[3];
    $humidity = (float)$row[2];
    if ($temperature === 0.0 || $timestamp === false) continue;

    $dayMonth = date('m-d', $timestamp);
    $slot = (int)date('G', $timestamp) * 2 + ((int)date('i', $timestamp) >= 30 ? 1 : 0);
    $totals[$site][$dayMonth][$slot] ??= [0.0, 0.0, 0];
    $totals[$site][$dayMonth][$slot][0] += $temperature;
    $totals[$site][$dayMonth][$slot][1] += $humidity;
    $totals[$site][$dayMonth][$slot][2]++;
}
fclose($handle);

$cache = [];
foreach ($totals as $site => $days) {
    foreach ($days as $dayMonth => $slots) {
        for ($slot = 0; $slot < 48; $slot++) {
            $values = $slots[$slot] ?? [0.0, 0.0, 0];
            $count = max(1, $values[2]);
            $cache[$site][$dayMonth][$slot] = [round($values[0] / $count, 1), round($values[1] / $count, 1)];
        }
    }
}

file_put_contents($target, json_encode($cache, JSON_UNESCAPED_SLASHES));
echo "Wrote {$target}\n";
