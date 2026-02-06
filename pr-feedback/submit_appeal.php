<?php
ob_start();
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

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
// 2. Azure Blob config
// ---------------------------
$accountName = getenv('AZURE_STORAGE_ACCOUNT');
$accountKey  = getenv('AZURE_STORAGE_KEY');
$container   = getenv('AZURE_STORAGE_CONTAINER');

$connectionString = "DefaultEndpointsProtocol=https;AccountName=$accountName;AccountKey=$accountKey";
$blobClient = BlobRestProxy::createBlobService($connectionString);

// ---------------------------
// 3. Get POST data
// ---------------------------
$pr_id = $_POST['pr_id'] ?? null;
$builder_answers = $_POST['builder_answer'] ?? [];
$explanations = $_POST['explanation'] ?? [];

if (!$pr_id) {
    die("Invalid PR ID.");
}

// ---------------------------
// 4. Prevent duplicate appeal
// ---------------------------
$sql = "SELECT appeal_id FROM pr_appeals WHERE pr_id = ?";
$stmt = sqlsrv_prepare($conn, $sql, [$pr_id]);
sqlsrv_execute($stmt);

if (sqlsrv_has_rows($stmt)) {
    die("This PR has already been appealed.");
}

// ---------------------------
// 5. Get builder email
// ---------------------------
$sql = "SELECT builder_email FROM pr_submissions WHERE pr_id = ?";
$stmt = sqlsrv_prepare($conn, $sql, [$pr_id]);
sqlsrv_execute($stmt);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$row) {
    die("PR submission not found.");
}
$builder_email = $row['builder_email'];

// ---------------------------
// 6. Insert pr_appeals (FIXED)
// ---------------------------
$sql = "
INSERT INTO pr_appeals (pr_id, builder_email, submission_date)
OUTPUT INSERTED.appeal_id
VALUES (?, ?, GETDATE())
";

$stmt = sqlsrv_prepare($conn, $sql, [$pr_id, $builder_email]);
if (!sqlsrv_execute($stmt)) {
    die(print_r(sqlsrv_errors(), true));
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$appeal_id = $row['appeal_id'] ?? null;

if (!$appeal_id) {
    die("Failed to retrieve appeal_id.");
}

// ---------------------------
// 7. Insert appeal items
// ---------------------------
foreach ($builder_answers as $qid => $answer) {

    if (strtolower($answer) !== 'not applicable') {
        continue;
    }

    $explanation = $explanations[$qid] ?? '';
    $uploadedFiles = [];

    if (!empty($_FILES['builder_images']['name'][$qid])) {
        foreach ($_FILES['builder_images']['name'][$qid] as $idx => $origName) {

            $tmpName = $_FILES['builder_images']['tmp_name'][$qid][$idx];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;

            $blobName = "appeal/q{$qid}/" . uniqid() . "_" . basename($origName);

            try {
                $blobClient->createBlockBlob(
                    $container,
                    $blobName,
                    fopen($tmpName, 'r')
                );
                $uploadedFiles[] = $blobName;
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }
    }

    $images_json = json_encode($uploadedFiles);

    $sql = "
    INSERT INTO pr_appeal_items
    (appeal_id, question_id, builder_answer, explanation, image_paths)
    VALUES (?, ?, ?, ?, ?)
    ";

    $params = [$appeal_id, $qid, $answer, $explanation, $images_json];
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    sqlsrv_execute($stmt);
}

// ---------------------------
// 8. Close connection
// ---------------------------
sqlsrv_close($conn);

// ---------------------------
// 9. Redirect
// ---------------------------
header("Location: pr_feedback.php?pr_id=" . urlencode($pr_id));
exit;
