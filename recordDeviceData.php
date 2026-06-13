<?php

require_once 'load_and_predict.php';

$locationNames = [
    1 => 'Wynyard',
    2 => 'Launceston',
    3 => 'Smithton',
    4 => 'Hobart',
    5 => 'Campania',
];

// Check if all necessary parameters are present
if (
    isset($_GET['date']) && isset($locationNames[$_GET['site_id']])
) {
    
    // Initialize an empty string
    $str = '';

    // Check if the XML file exists
    $xmlFilePath = 'recordData.xml';
    if (file_exists($xmlFilePath)) {
        // If the XML file exists, read its content
        $str = file_get_contents($xmlFilePath);
    } else {
        // If the XML file does not exist, create it with an empty records element
        $str = "<?xml version='1.0' encoding='UTF-8'?>\n<records></records>";
        file_put_contents($xmlFilePath, $str);
    }

    // Create new XML data for appending
    $newData = "\n<record>
        <location_id>{$_GET['site_id']}</location_id>
        <location_name>{$locationNames[$_GET['site_id']]}</location_name>
        <date>{$_GET['date']}</date>
    </record>\n</records>";

    // Put the new data at the end of the XML document
    $str = str_replace("</records>", $newData, $str);

    // Write the updated XML back to the server
    $saveFile = file_put_contents($xmlFilePath, $str);

    // Check if file writing was successful and set HTTP response code accordingly
    if (!$saveFile) {
        http_response_code(405); // Method Not Allowed
    }

    // Send the data as a JSON response
    header('Content-Type: application/json');
    $response = generateMinMaxPredictions($_GET['site_id'], $_GET['date']);
    echo json_encode($response);
} else {
    echo json_encode(array("error" => "Location id not found"));
}
?>
