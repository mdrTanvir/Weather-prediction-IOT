<?php
ini_set('memory_limit', '1024M');

function logMessage($message)
{
    error_log(data_cleaning . phpdate('[Y-m-d H:i:s] ') . $message . "\n", 3, 'error.log');
}

function readCsvFiles($filenames)
{
    $tempFile = BASE_URL . 'Training_data/cleaned_training_data_temp.csv';
    file_put_contents($tempFile, ''); // Initialize the temporary file

    // Add header to the temporary file
    $header = ['site_number', 'date_time', 'relative_humidity', 'temperature'];
    writeCsvFile([$header], $tempFile, 'a');

    foreach ($filenames as $filename) {
        $handle = fopen($filename, "r");
        if ($handle !== FALSE) {
            // Skip the header row
            fgetcsv($handle, 1000, ",");

            $data = [];
            while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Ensure all necessary columns are set
                if ((isset($row[0]) && $row[0] !== null) ||
                    (isset($row[1]) && $row[1] !== null) ||
                    (isset($row[2]) && $row[2] !== null) ||
                    (isset($row[3]) && $row[3] !== null)) {
                    $data[] = [$row[0], $row[1], floatval($row[2]), floatval($row[3])];
                }

                // Process data in smaller chunks to avoid memory overflow
                if (count($data) >= 1000) {
                    $sanitizedChunk = sanitizeData($data);
                    writeCsvFile($sanitizedChunk, $tempFile, 'a');
                    $data = []; // Clear data to free memory
                }
            }

            // Process any remaining data
            if (!empty($data)) {
                $sanitizedChunk = sanitizeData($data);
                writeCsvFile($sanitizedChunk, $tempFile, 'a');
            }

            fclose($handle);
        }
    }

    return $tempFile;
}

function sanitizeData($data)
{
    $sanitizedData = [];
    $lastRow = null; // Temporary storage for rows with minutes other than '00' or '30'
    $dayData = [];
    $dayHumidity = [];
    $uniqueData = []; // Associative array to track unique rows
    $currentDate = '';
    $locationNo = [
        91107 => 1,
        91237 => 2,
        91292 => 3,
        94029 => 4,
        94212 => 5,
    ];
    foreach ($data as $row) {


        // Check if row[0] is set and not empty
        if (!isset($row[0]) || empty($row[0])) {
            continue;
        }

        $siteNo = isset($locationNo[$row[0]]) ? $locationNo[$row[0]] : null;
        if (!$siteNo) {
            continue;
        }

        $time = $row[1];
        $humidity = $row[2];
        $temperature = $row[3];

        // Check for temperature value of 0 and skip the row if true
        if ($temperature == 0) {
            continue;
        }

        // Generate a unique key based on site number and date time
        $key = $siteNo . '_' . $time;

        // Check for duplicacy and skip if found
        if (isset($uniqueData[$key])) {
            continue;
        } else {
            $uniqueData[$key] = true; // Mark as seen
        }

        $minute = date('i', strtotime($time));
        $date = date('Y-m-d', strtotime($time));

        // If the date changes, process the previous day's data
        if ($currentDate !== '' && $currentDate !== $date) {
            // Calculate average humidity for the previous day if there are data points
            if (count($dayData) > 0) {
                if (count($dayHumidity) > 0) {
                    $avgHumidity = array_sum($dayHumidity) / count($dayHumidity);
                } else {
                    $avgHumidity = null;
                }

                // Iterate over $dayData and update humidity with average if null
                foreach ($dayData as &$dayRow) {
                    if ($dayRow[2] === null && $avgHumidity !== null) {
                        $dayRow[2] = floatval(number_format($avgHumidity, 1, '.', ''));
                    }
                    // Add validated data to $sanitizedData
                    $sanitizedData[] = [$dayRow[0], $dayRow[1], $dayRow[2], $dayRow[3]];
                }
            }

            // Reset dayData and dayHumidity for the new day
            $dayData = [];
            $dayHumidity = [];
        }

        $currentDate = $date;

        // Check if the minute part of the time is not '00' or '30'
        if ($minute !== '00' && $minute !== '30') {
            // Store the current row temporarily in $lastRow
            $lastRow = $row;
            continue;
        }

        // If $lastRow is not empty and the minute part is '00' or '30', calculate average humidity and temperature
        if ($lastRow !== null && ($minute === '00' || $minute === '30')) {
            $avgHumidity = ($humidity + $lastRow[2]) / 2;
            $avgTemperature = ($temperature + $lastRow[3]) / 2;
            $humidity = floatval(number_format($avgHumidity, 1, '.', ''));
            $temperature = floatval(number_format($avgTemperature, 1, '.', ''));;

            $lastRow = null; // Reset $lastRow
        }

        // Check for humidity value of 100
        if ($humidity > 99 || $humidity == 0) {
            $humidity = null; // Set to null to calculate average later
        }
        // Add data to dayData array
        $dayData[] = [$siteNo, $time, $humidity, $temperature];

        // Add humidity to dayHumidity array
        if ($humidity !== null) {
            $dayHumidity[] = $humidity;
        }
    }

    // Process remaining data points for the last day
    if (count($dayData) > 0) {
        if (count($dayHumidity) > 0) {
            $avgHumidity = array_sum($dayHumidity) / count($dayHumidity);
        } else {
            $avgHumidity = null;
        }

        foreach ($dayData as &$dayRow) {
            if ($dayRow[2] === null && $avgHumidity !== null) {
                $dayRow[2] = floatval(number_format($avgHumidity, 1, '.', ''));
            }
            $sanitizedData[] = [$dayRow[0], $dayRow[1], $dayRow[2], $dayRow[3]];
        }
    }

    return $sanitizedData;
}

function writeCsvFile($data, $filename, $mode = 'w')
{
    $handle = fopen($filename, $mode);
    if ($handle !== FALSE) {

        // Write data
        foreach ($data as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    } else {
        echo "Error: Unable to open file for writing.";
    }
}

// Read data from the CSV files
$filenames = [
    BASE_URL . 'Raw_data/091107_Cleaned_Wynyard_9 km.csv',
    BASE_URL . 'Raw_data/091237_Cleaned_Launceston_1 km.csv',
    BASE_URL . 'Raw_data/091292_Cleaned_Smithton_3 km.csv',
    BASE_URL . 'Raw_data/094029_Cleaned_Hobart_6 km.csv',
    BASE_URL . 'Raw_data/094212_Cleaned_Campania_14 km.csv',

];

// Process and sanitize data in chunks
$tempFile = readCsvFiles($filenames);

// Rename temp file to final output
rename($tempFile, BASE_URL . 'Training_data/cleaned_training_data.csv');

echo "Data cleaned and written to cleaned_training_data.csv";
?>
