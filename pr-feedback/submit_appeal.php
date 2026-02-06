<?php
ob_start(); // Start output buffering
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

// ---------------------------
// 1. Azure SQL Connection (same as submit_review.php)
// ---------------------------
$connectionOptions = [
    "Database" => "events-pr-db",      // Azure SQL DB name
    "Uid"      => "qmsadmin",          // Azure SQL username
    "PWD"      => "Codegenqms!",       // Azure SQL password
    "Encrypt"  => true,
    "LoginTimeout" => 60
];

$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) {
    die("Connection failed: " . print_r(sqlsrv_errors(), true));
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
$submission_id = $_POST['pr_id'] ?? null;
$builder_answers = $_POST['builder_answer'] ?? [];
$explanations = $_POST['explanation'] ?? [];

// ---------------------------
// 4. Validate submission ID
// ---------------------------
if (!$submission_id) {
    die("Invalid PR ID.");
}

// ---------------------------
// 5. Check if PR already appealed
// ---------------------------
$sql = "SELECT id FROM pr_appeals WHERE submission_id = ?";
$params = [$submission_id];
$stmt = sqlsrv_prepare($conn, $sql, $params);
if (!$stmt) die("Prepare failed: " . print_r(sqlsrv_errors(), true));
if (!sqlsrv_execute($stmt)) die("Execute failed: " . print_r(sqlsrv_errors(), true));

if (sqlsrv_has_rows($stmt)) {
    die("This PR has already been appealed.");
}

// ---------------------------
// 6. Insert into pr_appeals
// ---------------------------
// We'll put a default reason "Appeal submitted"
$sql = "INSERT INTO pr_appeals (submission_id, reason, created_at) VALUES (?, ?, GETDATE())";
$params = [$submission_id, 'Appeal submitted'];
$stmt = sqlsrv_prepare($conn, $sql, $params);
if (!$stmt) die("Prepare failed (pr_appeals): " . print_r(sqlsrv_errors(), true));
if (!sqlsrv_execute($stmt)) die("Execute failed (pr_appeals): " . print_r(sqlsrv_errors(), true));

// Get the new appeal ID
$appeal_id_stmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS id");
$appeal_row = sqlsrv_fetch_array($appeal_id_stmt, SQLSRV_FETCH_ASSOC);
$appeal_id = $appeal_row['id'] ?? null;
if (!$appeal_id) die("Could not retrieve newly inserted appeal ID.");

// ---------------------------
// 7. Loop through each question
// ---------------------------
foreach ($builder_answers as $qid => $answer) {
    $explanation = $explanations[$qid] ?? "";

    // Only save if builder disagrees (Not Applicable)
    if (strtolower($answer) !== "not applicable") continue;

    // ---------------------------
    // 7a. Handle appeal image uploads to Azure Blob
    // ---------------------------
    $uploadedFiles = [];

    if (isset($_FILES['builder_images']['name'][$qid]) &&
        !empty($_FILES['builder_images']['name'][$qid][0])) {

        foreach ($_FILES['builder_images']['name'][$qid] as $idx => $origName) {
            $tmpName = $_FILES['builder_images']['tmp_name'][$qid][$idx];
            $ext = pathinfo($origName, PATHINFO_EXTENSION);

            $allowed = ['jpg','jpeg','png','webp'];
            if (!in_array(strtolower($ext), $allowed)) continue;

            $blobName = "appeal/q{$qid}/" . uniqid() . "_" . basename($origName);
            $content = fopen($tmpName, 'r');

            try {
                $blobClient->createBlockBlob($container, $blobName, $content);
                $uploadedFiles[] = $blobName;
            } catch (Exception $e) {
                error_log("Failed to upload appeal image: " . $e->getMessage());
            }
        }
    }

    $images_json = json_encode($uploadedFiles);

    // ---------------------------
    // 7b. Insert into pr_appeal_items (match your DB)
    // ---------------------------
    $sql = "INSERT INTO pr_appeal_items (appeal_id, question_id, remarks) VALUES (?, ?, ?)";
    $params = [$appeal_id, $qid, $explanation]; // we store explanation in remarks
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if (!$stmt) die("Prepare failed (pr_appeal_items): " . print_r(sqlsrv_errors(), true));
    if (!sqlsrv_execute($stmt)) die("Execute failed (pr_appeal_items): " . print_r(sqlsrv_errors(), true));
}

// ---------------------------
// 8. Close connection
// ---------------------------
sqlsrv_close($conn);

// ---------------------------
// 9. Optionally send email notification
// ---------------------------
$sendEmailUrl = "https://eventsprguide.infinityfree.me/pr-feedback/send_appeal_email.php?pr_id=" . urlencode($submission_id);
@file_get_contents($sendEmailUrl);

// ---------------------------
// 10. Redirect back to feedback page
// ---------------------------
header("Location: pr_feedback.php?pr_id=" . urlencode($submission_id));
exit;
?>
