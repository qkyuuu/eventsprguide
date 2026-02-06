<?php
ob_start(); // Start output buffering
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '../vendor/autoload.php';
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
    $errors = sqlsrv_errors();
    die(print_r($errors, true));
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
$builder_email = ""; // will fetch from pr_submissions

// ---------------------------
// 4. Validate PR ID
// ---------------------------
if (!$pr_id) {
    die("Invalid PR ID.");
}

// ---------------------------
// 5. Check if PR already appealed
// ---------------------------
$sql = "SELECT appeal_id FROM pr_appeals WHERE pr_id = ?";
$params = [$pr_id];
$stmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($stmt);

if (sqlsrv_has_rows($stmt)) {
    die("This PR has already been appealed.");
}

// ---------------------------
// 6. Get builder email from pr_submissions
// ---------------------------
$sql = "SELECT builder_email FROM pr_submissions WHERE pr_id = ?";
$params = [$pr_id];
$stmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($stmt);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$row) {
    die("PR Submission not found.");
}
$builder_email = $row['builder_email'];

// ---------------------------
// 7. Insert into pr_appeals
// ---------------------------
$sql = "INSERT INTO pr_appeals (pr_id, builder_email) VALUES (?, ?)";
$params = [$pr_id, $builder_email];
$stmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($stmt);

// Get the newly inserted appeal_id
$appeal_id_stmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS id");
$appeal_row = sqlsrv_fetch_array($appeal_id_stmt, SQLSRV_FETCH_ASSOC);
$appeal_id = $appeal_row['id'];

// ---------------------------
// 8. Loop through each question
// ---------------------------
foreach ($builder_answers as $qid => $answer) {
    $explanation = $explanations[$qid] ?? "";

    // Only save if builder disagrees (Not Applicable)
    if (strtolower($answer) !== "not applicable") {
        continue;
    }

    // ---------------------------
    // 8a. Handle appeal image uploads to Azure Blob
    // ---------------------------
    $uploadedFiles = [];

    if (isset($_FILES['builder_images']['name'][$qid]) &&
        !empty($_FILES['builder_images']['name'][$qid][0])) {

        foreach ($_FILES['builder_images']['name'][$qid] as $idx => $origName) {

            $tmpName = $_FILES['builder_images']['tmp_name'][$qid][$idx];
            $ext = pathinfo($origName, PATHINFO_EXTENSION);

            // Optional: only allow images
            $allowed = ['jpg','jpeg','png','webp'];
            if (!in_array(strtolower($ext), $allowed)) continue;

            // Distinct Azure path for appeal images
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
    // 8b. Insert into pr_appeal_items
    // ---------------------------
    $sql = "INSERT INTO pr_appeal_items (appeal_id, question_id, builder_answer, explanation, image_paths)
            VALUES (?, ?, ?, ?, ?)";
    $params = [$appeal_id, $qid, $answer, $explanation, $images_json];
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    sqlsrv_execute($stmt);
}

// ---------------------------
// 9. Close connection
// ---------------------------
sqlsrv_close($conn);

// ---------------------------
// 10. Optionally send email notification
// ---------------------------
$sendEmailUrl = "https://eventsprguide.infinityfree.me/pr-feedback/send_appeal_email.php?pr_id=" . urlencode($pr_id);
@file_get_contents($sendEmailUrl);

// ---------------------------
// 11. Redirect back to feedback page
// ---------------------------
header("Location: pr_feedback.php?pr_id=" . urlencode($pr_id));
exit;
?>
