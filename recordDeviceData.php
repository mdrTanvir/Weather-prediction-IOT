<?php

require_once 'load_and_predict.php';

$locationNames = [
    1 => 'Wynyard',
    2 => 'Launceston',
    3 => 'Smithton',
    4 => 'Hobart',
    5 => 'Campania',
];

header('Content-Type: application/json');

$siteId = filter_input(INPUT_GET, 'site_id', FILTER_VALIDATE_INT);
$date = $_GET['date'] ?? '';
$parsedDate = DateTime::createFromFormat('!Y-m-d', $date);

if (!$siteId || !isset($locationNames[$siteId]) || !$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    http_response_code(422);
    echo json_encode(['error' => 'Provide a valid date (YYYY-MM-DD) and site_id (1-5).']);
    exit;
}

try {
    echo json_encode(generateMinMaxPredictions($siteId, $date));
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Prediction service is temporarily unavailable.']);
}

/*
 * This endpoint deliberately does not persist requests. Serverless hosts such
 * as Vercel use an ephemeral filesystem, and the dashboard needs only the
 * prediction response for each device request.
 */
