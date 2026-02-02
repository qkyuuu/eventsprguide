<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Always return JSON
header('Content-Type: application/json');

// 1. Connection using Azure SQL driver (matches your other files)
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid" => "qmsadmin",
    "PWD" => "Codegenqms!",
    "Encrypt" => true
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    echo json_encode([
        'success' => false,
        'message' => "Database connection failed: " . print_r(sqlsrv_errors(), true)
    ]);
    exit;
}

// 2. Validate required POST fields
if (!isset($_POST['pr_id'], $_POST['status'])) {
    echo json_encode(['success' => false, 'message' => 'Missing PRID or status.']);
    exit;
}

$pr_id = trim($_POST['pr_id']);
$status = trim($_POST['status']);
$answers_json = $_POST['answers'] ?? null;

// 3. Extract review_status if it exists
$review_status_json = null;
if ($answers_json) {
    $decoded = json_decode($answers_json, true);
    if (isset($decoded['review_status']) && is_array($decoded['review_status'])) {
        $review_status_json = json_encode($decoded['review_status']);
    }
}

// 4. Update Database using sqlsrv driver
if ($review_status_json) {
    $sql = "UPDATE pr_submissions SET status = ?, review_status = ? WHERE pr_id = ?";
    $params = [$status, $review_status_json, $pr_id];
} else {
    $sql = "UPDATE pr_submissions SET status = ? WHERE pr_id = ?";
    $params = [$status, $pr_id];
}

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    echo json_encode([
        'success' => true,
        'message' => "Status for PRID '$pr_id' updated to '$status'."
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update database: ' . print_r(sqlsrv_errors(), true)
    ]);
}
?>
