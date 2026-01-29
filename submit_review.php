<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Azure SQL Database connection using Environment Variable
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid" => getenv('qmsadmin'),       // or use directly your SQL login
    "PWD" => getenv('Codegenqms!'),   // or the environment variable you set
    "Encrypt" => true
];

// Server name, must include tcp: and port
$serverName = "tcp:qms-server.database.windows.net,1433";

// Connect to SQL Server
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// 2. Collect answers dynamically
$answers = [];
foreach ($_POST as $key => $value) {
    if (strpos($key, 'q') === 0 || strpos($key, 'fatality') === 0 || strpos($key, 'remarks') === 0) {
        $answers[$key] = $value;
    }
}


// 3. Handle file uploads grouped by question
$imagePaths = [];
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

foreach ($_FILES as $inputName => $fileArray) {
    // Match inputs like image_q1, image_q2, etc.
    if (preg_match('/^image_q(\d+)$/', $inputName, $matches)) {
        $qId = $matches[1];
        $imagePaths['q' . $qId] = [];

        $filesCount = count($fileArray['name']);

        for ($i = 0; $i < $filesCount; $i++) {
            if ($fileArray['error'][$i] === UPLOAD_ERR_OK) {
                $filename = uniqid() . "_" . basename($fileArray['name'][$i]);
                $targetFile = $uploadDir . $filename;
                if (move_uploaded_file($fileArray['tmp_name'][$i], $targetFile)) {
                    $imagePaths['q' . $qId][] = $filename;
                }
            }
        }
    }
}


// 4. Generate PRID dynamically by fetching all pr_id and finding max number
$result = $mysqli->query("SELECT pr_id FROM pr_submissions");

$max_num = 0;
while ($row = $result->fetch_assoc()) {
    $num = (int)substr($row['pr_id'], 4);
    if ($num > $max_num) {
        $max_num = $num;
    }
}

$next_pr_id = 'PRID' . str_pad($max_num + 1, 6, '0', STR_PAD_LEFT);

// 5. Save to DB
$task_name = $_POST['task_name'];
$pr_name = $_POST['peer_reviewer_name'];
$pr_email = $_POST['peer_reviewer_email'];
$builder_name = $_POST['builder_name'];
$builder_email = $_POST['builder_email'];
$submitter_email = 'v-jopastoral@microsoft.com'; // hardcoded for now
$answers_json = json_encode($answers);
$images_json = json_encode($imagePaths);
$status = 'Pending';

$stmt = $mysqli->prepare("INSERT INTO pr_submissions (pr_id, submitter_email, task_name, peer_reviewer_name, peer_reviewer_email, builder_name, builder_email, answers, image_paths, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssssss", $next_pr_id, $submitter_email, $task_name, $pr_name, $pr_email, $builder_name, $builder_email, $answers_json, $images_json, $status);

$stmt->execute();
$pr_id = $next_pr_id;
$stmt->close();
$mysqli->close();


// 6. Redirect the user to the feedback page after saving
header("Location: https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=$pr_id");

exit;

?>