<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Connection using Azure SQL driver
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid" => "qmsadmin",
    "PWD" => "Codegenqms!",
    "Encrypt" => true,
    "LoginTimeout" => 60 // Wait up to 60 seconds to connect
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) { die(print_r(sqlsrv_errors(), true)); }

// 2. Collect answers
$answers = [];
foreach ($_POST as $key => $value) {
    if (strpos($key, 'q') === 0 || strpos($key, 'fatality') === 0 || strpos($key, 'remarks') === 0) {
        $answers[$key] = $value;
    }
}

// 3. Handle file uploads
$imagePaths = [];
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

foreach ($_FILES as $inputName => $fileArray) {
    if (preg_match('/^image_q(\d+)$/', $inputName, $matches)) {
        $qId = $matches[1];
        $imagePaths['q' . $qId] = [];
        if (isset($fileArray['name']) && is_array($fileArray['name'])) {
            for ($i = 0; $i < count($fileArray['name']); $i++) {
                if ($fileArray['error'][$i] === UPLOAD_ERR_OK) {
                    $filename = uniqid() . "_" . basename($fileArray['name'][$i]);
                    if (move_uploaded_file($fileArray['tmp_name'][$i], $uploadDir . $filename)) {
                        $imagePaths['q' . $qId][] = $filename;
                    }
                }
            }
        }
    }
}

// 4. Generate PRID
$tsql = "SELECT pr_id FROM pr_submissions";
$getIds = sqlsrv_query($conn, $tsql);
$max_num = 0;
if ($getIds) {
    while ($row = sqlsrv_fetch_array($getIds, SQLSRV_FETCH_ASSOC)) {
        $num = (int)substr($row['pr_id'], 4);
        if ($num > $max_num) { $max_num = $num; }
    }
}
$next_pr_id = 'PRID' . str_pad($max_num + 1, 6, '0', STR_PAD_LEFT);

// 5. Save to DB
$params = [
    $next_pr_id, 
    'v-jopastoral@microsoft.com', 
    $_POST['task_name'] ?? '',
    $_POST['peer_reviewer_name'] ?? '', 
    $_POST['peer_reviewer_email'] ?? '',
    $_POST['builder_name'] ?? '', 
    $_POST['builder_email'] ?? '',
    json_encode($answers), 
    json_encode($imagePaths), 
    'Pending'
];

$sql = "INSERT INTO pr_submissions (pr_id, submitter_email, task_name, peer_reviewer_name, peer_reviewer_email, builder_name, builder_email, answers, image_paths, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) { die(print_r(sqlsrv_errors(), true)); }

// 6. Redirect to home
header("Location: /index.html?success=true");
exit;
?>
