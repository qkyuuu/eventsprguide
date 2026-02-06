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
    die("Database Connection failed: " . print_r(sqlsrv_errors(), true));
}

// 2. Azure Blob config
$accountName = getenv('AZURE_STORAGE_ACCOUNT');
$accountKey  = getenv('AZURE_STORAGE_KEY');
$container   = getenv('AZURE_STORAGE_CONTAINER');

$connectionString = "DefaultEndpointsProtocol=https;AccountName=$accountName;AccountKey=$accountKey";
$blobClient = BlobRestProxy::createBlobService($connectionString);

// 3. Get POST data
$pr_display_id = $_POST['pr_id'] ?? null; 
$builder_answers = $_POST['builder_answer'] ?? [];
$explanations = $_POST['explanation'] ?? [];

if (!$pr_display_id) {
    die("Error: Missing PR ID.");
}

// 4. Resolve internal INT ID
// Converts string "PRID000018" to its internal integer ID for database foreign keys
$sql_find = "SELECT id FROM pr_submissions WHERE pr_id = ?";
$stmt_find = sqlsrv_prepare($conn, $sql_find, [$pr_display_id]);
if (!sqlsrv_execute($stmt_find)) {
    die("Error finding submission: " . print_r(sqlsrv_errors(), true));
}
$sub_row = sqlsrv_fetch_array($stmt_find, SQLSRV_FETCH_ASSOC);

if (!$sub_row) {
    die("Error: PR Submission record not found in database.");
}
$internal_sub_id = $sub_row['id'];

// 5. Check if PR already appealed to prevent duplicates
$sql_check = "SELECT id FROM pr_appeals WHERE submission_id = ?";
$stmt_check = sqlsrv_prepare($conn, $sql_check, [$internal_sub_id]);
sqlsrv_execute($stmt_check);

if (sqlsrv_has_rows($stmt_check)) {
    die("This PR has already been appealed.");
}

// 6. Insert into pr_appeals
// We capture the new auto-incremented ID using OUTPUT INSERTED.id
$sql_appeal = "INSERT INTO pr_appeals (submission_id, reason, created_at) 
               OUTPUT INSERTED.id 
               VALUES (?, ?, GETDATE())";
$params_appeal = [$internal_sub_id, 'Appeal submitted by builder'];
$stmt_appeal = sqlsrv_prepare($conn, $sql_appeal, $params_appeal);

if (!sqlsrv_execute($stmt_appeal)) {
    die("Failed to create appeal record: " . print_r(sqlsrv_errors(), true));
}

$appeal_row = sqlsrv_fetch_array($stmt_appeal, SQLSRV_FETCH_ASSOC);
$new_appeal_id = $appeal_row['id'];

// 7. Loop through each question answered by the builder
foreach ($builder_answers as $qid => $answer) {
    
    // Check if the answer is "Not Applicable" (the trigger for an appeal item)
    // We use trim() and strtolower() to ensure the match is perfect
    if (strtolower(trim($answer)) !== "not applicable") {
        continue; 
    }

    $explanation = $explanations[$qid] ?? "";
    $uploadedFiles = [];

    // 7a. Handle Image Uploads to 'appeals/' virtual folder
    if (isset($_FILES['builder_images']['name'][$qid]) && !empty($_FILES['builder_images']['name'][$qid][0])) {
        foreach ($_FILES['builder_images']['name'][$qid] as $idx => $origName) {
            if ($_FILES['builder_images']['error'][$qid][$idx] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['builder_images']['tmp_name'][$qid][$idx];
                $ext = pathinfo($origName, PATHINFO_EXTENSION);
                
                // Unique filename with appeal prefix to avoid clashes with review images
                $blobName = "appeals/builder_appeal_" . uniqid() . "." . $ext;
                $content = fopen($tmpName, 'r');

                try {
                    $blobClient->createBlockBlob($container, $blobName, $content);
                    $uploadedFiles[] = $blobName;
                } catch (Exception $e) {
                    error_log("Azure Blob Upload failed: " . $e->getMessage());
                }
            }
        }
    }

    $images_json = json_encode($uploadedFiles);

    // 7b. Insert into pr_appeal_items
    // Maps builder explanation to 'remarks' column
    $sql_item = "INSERT INTO pr_appeal_items (appeal_id, question_id, remarks, image_paths) 
                 VALUES (?, ?, ?, ?)";
    $params_item = [$new_appeal_id, $qid, $explanation, $images_json];
    $stmt_item = sqlsrv_prepare($conn, $sql_item, $params_item);
    
    if (!sqlsrv_execute($stmt_item)) {
        // If this fails, it will stop the script and tell you why the record is missing
        die("Error saving appeal item for Question ID $qid: " . print_r(sqlsrv_errors(), true));
    }
}

sqlsrv_close($conn);

// 8. Redirect back to feedback page with a success parameter
header("Location: pr_feedback.php?pr_id=" . urlencode($pr_display_id) . "&status=appeal_success");
exit;
?>
