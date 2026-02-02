<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// 1. Azure SQL Connection Details
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid" => "qmsadmin",
    "PWD" => "Codegenqms!",
    "Encrypt" => true
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// 2. Validate Inputs
if (!isset($_POST['pr_id'], $_POST['status'])) {
    echo json_encode(['success' => false, 'message' => 'Missing PRID or status.']);
    exit;
}

$pr_id = trim($_POST['pr_id']);
$status = trim($_POST['status']);
$answers_json = $_POST['answers'] ?? null;

// Parse review status from the JSON sent by pr_feedback.php
$review_status_json = null;
if ($answers_json) {
    $decoded = json_decode($answers_json, true);
    if (isset($decoded['review_status'])) {
        $review_status_json = json_encode($decoded['review_status']);
    }
}

// 3. Update Database using sqlsrv driver
if ($review_status_json) {
    $sql = "UPDATE pr_submissions SET status = ?, review_status = ? WHERE pr_id = ?";
    $params = [$status, $review_status_json, $pr_id];
} else {
    $sql = "UPDATE pr_submissions SET status = ? WHERE pr_id = ?";
    $params = [$status, $pr_id];
}

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    echo json_encode(['success' => true, 'message' => "Updated PR $pr_id to $status"]);
} else {
    echo json_encode(['success' => false, 'message' => sqlsrv_errors()]);
}
?>
