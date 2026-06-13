<?php

require_once 'load_and_predict.php';

function calculateHalfHourlyAverages($inputDate, $siteId) {
    $csvFilePath = 'Training_data/cleaned_training_data.csv';
    // Parse input date to extract day and month
    $inputDayMonth = date("d-m", strtotime($inputDate));

    // Initialize an array to store temperatures for each half-hour interval
    $data = array_fill(0, 48, []); // 48 half-hour intervals in a day

    if (($handle = fopen($csvFilePath, 'r')) !== false) {
        // Skip header row
        fgetcsv($handle, 0, ',', '"', '\\');

        // Iterate through the dataset
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $currentSiteId = (int)$row[0];
            $date = date("d-m", strtotime($row[1]));
            $temperature = (float)$row[3];
            $humidity = (float)$row[2];

            // Check if the record matches the input date and site ID
            if ($date === $inputDayMonth && $currentSiteId === $siteId) {
                $time = date("H:i", strtotime($row[1])); // Extract time (HH:mm)
                $halfHourIndex = floor((strtotime($time) - strtotime("00:00")) / (30 * 60)); // Calculate half-hour index

                $data[$halfHourIndex][] = [
                    'siteId' => $siteId,
                    'temperature' => $temperature,
                    'humidity' => $humidity
                ];
            }
        }

        fclose($handle);
    }

    // Initialize arrays to store the total temperature, humidity, and count for each half-hour interval
    $halfHourlyAverages = array_fill(0, 48, ['totalTemperature' => 0, 'totalHumidity' => 0, 'count' => 0]);

    foreach ($data as $intervalIndex => $intervalData) {
        foreach ($intervalData as $record) {
            // Check if the record matches the input site ID
            if ($record['siteId'] == $siteId) {
                $halfHourlyAverages[$intervalIndex]['totalTemperature'] += $record['temperature'];
                $halfHourlyAverages[$intervalIndex]['totalHumidity'] += $record['humidity'];
                $halfHourlyAverages[$intervalIndex]['count']++;
            }
        }
    }

    $averageData = [];

    // Calculate the average temperature and humidity for each half-hour interval
    foreach ($halfHourlyAverages as $intervalIndex => $totals) {
        $averageTemperature = $totals['count'] > 0 ? $totals['totalTemperature'] / $totals['count'] : 0;
        $averageHumidity = $totals['count'] > 0 ? $totals['totalHumidity'] / $totals['count'] : 0;

        $interval = date("H:i", strtotime("00:00") + ($intervalIndex * 30 * 60)); // Calculate start time of the interval

        $averageData[] = [
            'date' => $inputDate,
            'time' => $interval,
            'avgTemp' => round($averageTemperature,1),
            'avgHumid' => round($averageHumidity,1)
        ];
    }

    return $averageData;
}

function fetchLiveWeatherData($siteId, $date) {
    $coordinates = [
        1 => ['lat' => -40.9999, 'lon' => 145.7283], // Wynyard
        2 => ['lat' => -41.4332, 'lon' => 147.1441], // Launceston
        3 => ['lat' => -40.8500, 'lon' => 145.1167], // Smithton
        4 => ['lat' => -42.8821, 'lon' => 147.3272], // Hobart
        5 => ['lat' => -42.6500, 'lon' => 147.4500], // Campania
    ];

    if (!isset($coordinates[$siteId])) return null;

    $lat     = $coordinates[$siteId]['lat'];
    $lon     = $coordinates[$siteId]['lon'];
    $dateStr = date('Y-m-d', strtotime($date));

    $url = "https://api.open-meteo.com/v1/forecast"
         . "?latitude={$lat}&longitude={$lon}"
         . "&daily=temperature_2m_max,temperature_2m_min,relative_humidity_2m_max,relative_humidity_2m_min"
         . "&timezone=Australia%2FHobart"
         . "&start_date={$dateStr}&end_date={$dateStr}";

    $ctx  = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) return null;

    $data = json_decode($json, true);
    if (!$data || empty($data['daily']['time'])) return null;

    $d = $data['daily'];
    return [
        'actualMaxTemp'  => round((float)$d['temperature_2m_max'][0],       1),
        'actualMinTemp'  => round((float)$d['temperature_2m_min'][0],       1),
        'actualMaxHumid' => round((float)$d['relative_humidity_2m_max'][0], 1),
        'actualMinHumid' => round((float)$d['relative_humidity_2m_min'][0], 1),
        'source'         => 'Open-Meteo API',
    ];
}

function getTodayActuals($siteId, $date) {
    $csvFilePath = 'Training_data/cleaned_training_data.csv';
    $targetDate  = date('Y-m-d', strtotime($date));

    $temps  = [];
    $humids = [];

    if (($handle = fopen($csvFilePath, 'r')) !== false) {
        fgetcsv($handle, 0, ',', '"', '\\'); // skip header
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ((int)$row[0] !== $siteId) continue;
            if (substr(trim($row[1]), 0, 10) !== $targetDate) continue;
            $t = (float)$row[3];
            $h = (float)$row[2];
            if ($t !== 0.0) $temps[]  = $t;
            if ($h !== 0.0) $humids[] = $h;
        }
        fclose($handle);
    }

    if (empty($temps)) return null;

    return [
        'actualMaxTemp'  => round(max($temps),  1),
        'actualMinTemp'  => round(min($temps),  1),
        'actualMaxHumid' => round(max($humids), 1),
        'actualMinHumid' => round(min($humids), 1),
    ];
}

