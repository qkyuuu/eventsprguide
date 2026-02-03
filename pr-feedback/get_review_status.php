<?php
header('Content-Type: application/json');

// Connect to Azure SQL (Matches your pr_feedback.php)
$connectionOptions = ["Database" => "events-pr-db", "Uid" => "qmsadmin", "PWD" => "Codegenqms!", "Encrypt" => true];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

$pr_id = $_GET['pr_id'] ?? '';
$sql = "SELECT status FROM pr_submissions WHERE pr_id = ?";
$stmt = sqlsrv_query($conn, $sql, array($pr_id));
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

// This ensures the browser gets valid JSON, not a sentence!
echo json_encode([
    "success" => true,
    "status" => $row['status'] ?? 'Pending'
]);
?>
