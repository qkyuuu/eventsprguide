<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------------------------
// 1. Azure SQL Connection
// ---------------------------
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid"      => "qmsadmin",
    "PWD"      => "Codegenqms!",
    "Encrypt"  => true,
    "LoginTimeout" => 60
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// ---------------------------
// 2. Collect Answers
// ---------------------------
$answers = [];
foreach ($_POST as $key => $value) {
    if (
        strpos($key, 'q') === 0 ||
        strpos($key, 'fatality') === 0 ||
        strpos($key, 'remarks') === 0
    ) {
        $answers[$key] = $value;
    }
}

// ---------------------------
// 3. Collect Image Filenames ONLY
// ---------------------------
// Images are assumed to already exist in GitHub /uploads
$imagePaths = [];

foreach ($_POST as $key => $value) {
    // Expecting inputs like: image_q1[] = filename.png
    if (preg_match('/^image_q(\d+)$/', $key, $matches)) {
        $qId = $matches[1];

        if (!empty($value)) {
            $imagePaths['q' . $qId] = is_array($value) ? $value : [$value];
        }
    }
}

// ---------------------------
// 4. Generate PR ID
// ---------------------------
$getIds = sqlsrv_query($conn, "SELECT pr_id FROM pr_submissions");
$max_num = 0;

while ($row = sqlsrv_fetch_array($getIds, SQLSRV_FETCH_ASSOC)) {
    $num = (int)substr($row['pr_id'], 4);
    if ($num > $max_num) {
        $max_num = $num;
    }
}

$next_pr_id = 'PRID' . str_pad($max_num + 1, 6, '0', STR_PAD_LEFT);

// ---------------------------
// 5. Insert into Database
// ---------------------------
$params = [
    $next_pr_id,
    'v-jopastoral@microsoft.com', // submitter
    $_POST['task_name'] ?? '',
    $_POST['peer_reviewer_name'] ?? '',
    $_POST['peer_reviewer_email'] ?? '',
    $_POST['builder_name'] ?? '',
    $_POST['builder_email'] ?? '',
    json_encode($answers, JSON_UNESCAPED_UNICODE),
    json_encode($imagePaths, JSON_UNESCAPED_UNICODE),
    'Pending'
];

$sql = "
INSERT INTO pr_submissions
(pr_id, submitter_email, task_name, peer_reviewer_name, peer_reviewer_email,
 builder_name, builder_email, answers, image_paths, status)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

// ---------------------------
// 6. Redirect
// ---------------------------
header("Location: pr-feedback/pr_feedback.php?pr_id=$next_pr_id&success=true");
exit;
?>
