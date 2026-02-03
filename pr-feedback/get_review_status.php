<?php
header('Content-Type: application/json');

// 1. Get PR ID from the request
$pr_id = $_GET['pr_id'] ?? null;

if (!$pr_id) {
    echo json_encode(['success' => false, 'message' => 'PRID is required.']);
    exit;
}

// 2. Azure SQL Database Connection
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid" => "qmsadmin",
    "PWD" => "Codegenqms!",
    "Encrypt" => true
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// 3. Fetch the status from the database
$sql = "SELECT status FROM pr_submissions WHERE pr_id = ?";
$params = array($pr_id);
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Query failed.']);
    exit;
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if ($row) {
    // Return the status (e.g., 'Pending', 'Completed', 'Sent')
    echo json_encode([
        'success' => true,
        'status' => $row['status']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'PRID not found.'
    ]);
}

// Close connection
sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
