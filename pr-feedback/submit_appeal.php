<?php
ob_start(); 
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

// 1. Azure SQL Connection
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
    die("Connection failed: " . print_r(sqlsrv_errors(), true));
}

// 2. Azure Blob config
$accountName = getenv('AZURE_STORAGE_ACCOUNT');
$accountKey  = getenv('AZURE_STORAGE_KEY');
$container   = getenv('AZURE_STORAGE_CONTAINER');

$connectionString = "DefaultEndpointsProtocol=https;AccountName=$accountName;AccountKey=$accountKey";
$blobClient = BlobRestProxy::createBlobService($connectionString);

// 3. Get POST data
$pr_display_id = $_POST['pr_id'] ?? null; // e.g., "PRID000018"
$builder_answers = $_POST['builder_answer'] ?? [];
$explanations = $_POST['explanation'] ?? [];

if (!$pr_display_id) {
    die("Invalid PR ID.");
}

// ---------------------------------------------------------
// 4. RESOLVE ID: Convert "PRIDXXXX" string to integer ID
// ---------------------------------------------------------
// Your pr_appeals table needs an INT submission_id, not a VARCHAR string.
$sql_find = "SELECT id FROM pr_submissions WHERE pr_id = ?";
$stmt_find = sqlsrv_prepare($conn, $sql_find, [$pr_display_id]);
sqlsrv_execute($stmt_find);
$sub_row = sqlsrv_fetch_array($stmt_find, SQLSRV_FETCH_ASSOC);

if (!$sub_row) {
    die("Error: Submission record not found for " . htmlspecialchars($pr_display_id));
}
$internal_sub_id = $sub_row['id']; // This is the integer ID

// 5. Check if already appealed
$sql_check = "SELECT id FROM pr_appeals WHERE submission_id = ?";
$stmt_check = sqlsrv_prepare($conn, $sql_check, [$internal_sub_id]);
sqlsrv_execute($stmt_check);

if (sqlsrv_has_rows($stmt_check)) {
    die("This PR has already been appealed.");
}

// ---------------------------------------------------------
// 6. Insert into pr_appeals
// ---------------------------------------------------------
// Matches schema: submission_id (int), reason (nvarchar)
$sql_appeal = "INSERT INTO pr_appeals (submission_id, reason, created_at) 
               OUTPUT INSERTED.id 
               VALUES (?, ?, GETDATE())";
$params_appeal = [$internal_sub_id, 'Appeal submitted by builder'];
$stmt_appeal = sqlsrv_prepare($conn, $sql_appeal, $params_appeal);

if (!sqlsrv_execute($stmt_appeal)) {
    die("Execute failed (pr_appeals): " . print_r(sqlsrv_errors(), true));
}

// Fetch the newly created appeal's ID
$appeal_row = sqlsrv_fetch_array($stmt_appeal, SQLSRV_FETCH_ASSOC);
$new_appeal_id = $appeal_row['id'];

// ---------------------------------------------------------
// 7. Loop through each question
// ---------------------------------------------------------
foreach ($builder_answers as $qid => $answer) {
    // Only save if the builder chose "Not Applicable"
    if (strtolower($answer) !== "not applicable") continue;

    $explanation = $explanations[$qid] ?? "";
    $uploadedFiles = [];

    // 7a. Handle Image Uploads
    if (isset($_FILES['builder_images']['name'][$qid]) && !empty($_FILES['builder_images']['name'][$qid][0])) {
        foreach ($_FILES['builder_images']['name'][$qid] as $idx => $origName) {
            if ($_FILES['builder_images']['error'][$qid][$idx] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['builder_images']['tmp_name'][$qid][$idx];
                $ext = pathinfo($origName, PATHINFO_EXTENSION);
                
                // Organizing into virtual 'appeals/' folder
                $blobName = "appeals/appeal_{$pr_display_id}_Q{$qid}_" . uniqid() . "." . $ext;
                $content = fopen($tmpName, 'r');

                try {
                    $blobClient->createBlockBlob($container, $blobName, $content);
                    $uploadedFiles[] = $blobName;
                } catch (Exception $e) {
                    error_log("Upload failed: " . $e->getMessage());
                }
            }
        }
    }

    $images_json = json_encode($uploadedFiles);

    // 7b. Insert into pr_appeal_items
    // Matches schema: appeal_id (int), question_id (int), remarks (nvarchar)
    // IMPORTANT: Ensure you have added the 'image_paths' column to this table!
    $sql_item = "INSERT INTO pr_appeal_items (appeal_id, question_id, remarks, image_paths) 
                 VALUES (?, ?, ?, ?)";
    $params_item = [$new_appeal_id, $qid, $explanation, $images_json];
    $stmt_item = sqlsrv_prepare($conn, $sql_item, $params_item);
    
    if (!sqlsrv_execute($stmt_item)) {
        error_log("Item Insert failed: " . print_r(sqlsrv_errors(), true));
    }
}

sqlsrv_close($conn);

// 8. Redirect back to feedback page
header("Location: pr_feedback.php?pr_id=" . urlencode($pr_display_id) . "&status=appeal_success");
exit;
?>
