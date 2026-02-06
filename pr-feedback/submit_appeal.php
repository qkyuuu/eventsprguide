<?php
ob_start(); 
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load Azure SDK
require_once __DIR__ . '/../vendor/autoload.php';
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

// ---------------------------
// 1. Azure SQL Connection
// ---------------------------
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid"      => "qmsadmin",
    "PWD"      => "Codegenqms!",
    "Encrypt"  => true
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// ---------------------------
// 2. Azure Blob Connection
// ---------------------------
$accountName = getenv('AZURE_STORAGE_ACCOUNT');
$accountKey  = getenv('AZURE_STORAGE_KEY');
$container   = getenv('AZURE_STORAGE_CONTAINER');

$connectionString = "DefaultEndpointsProtocol=https;AccountName=$accountName;AccountKey=$accountKey";
$blobClient = BlobRestProxy::createBlobService($connectionString);

// ---------------------------
// 3. GET POST DATA
// ---------------------------
$pr_id = $_POST['pr_id'] ?? null;
$builder_answers = $_POST['builder_answer'] ?? [];
$explanations = $_POST['explanation'] ?? [];

if (!$pr_id) {
    die("Invalid PR ID.");
}

// ---------------------------
// 4. GET INTERNAL SUBMISSION ID
// ---------------------------
// Your pr_appeals table uses submission_id (int), which refers to the 'id' column of pr_submissions.
$sql_sub = "SELECT id FROM pr_submissions WHERE pr_id = ?";
$stmt_sub = sqlsrv_query($conn, $sql_sub, [$pr_id]);
$sub_row = sqlsrv_fetch_array($stmt_sub, SQLSRV_FETCH_ASSOC);

if (!$sub_row) {
    die("Error: PR Submission not found in the database.");
}
$internal_submission_id = $sub_row['id'];

// ---------------------------
// 5. INSERT INTO pr_appeals
// ---------------------------
// Mapping to your schema: 'submission_id' and 'reason'
$sql_appeal = "INSERT INTO pr_appeals (submission_id, reason) OUTPUT INSERTED.id VALUES (?, ?)";
$stmt_appeal = sqlsrv_query($conn, $sql_appeal, [$internal_submission_id, 'Appeal submitted by builder']);

if ($stmt_appeal === false) {
    die(print_r(sqlsrv_errors(), true));
}

$appeal_row = sqlsrv_fetch_array($stmt_appeal, SQLSRV_FETCH_ASSOC);
$appeal_id = $appeal_row['id']; 

// ---------------------------
// 6. LOOP THROUGH ITEMS & UPLOAD TO BLOB
// ---------------------------
foreach ($builder_answers as $qid => $answer) {
    $explanation = $explanations[$qid] ?? "";
    $uploadedFiles = [];

    // Handle Image Uploads for this Question
    if (isset($_FILES['builder_images']['name'][$qid]) && !empty($_FILES['builder_images']['name'][$qid][0])) {
        
        foreach ($_FILES['builder_images']['name'][$qid] as $idx => $origName) {
            if ($_FILES['builder_images']['error'][$qid][$idx] === UPLOAD_ERR_OK) {
                
                $tmpName = $_FILES['builder_images']['tmp_name'][$qid][$idx];
                $ext = pathinfo($origName, PATHINFO_EXTENSION);

                // Naming logic: adds "appeals/" prefix for virtual folder organization
                $blobName = "appeals/appeal_{$pr_id}_Q{$qid}_" . uniqid() . "." . $ext;

                // Upload to Azure Blob Storage
                $content = fopen($tmpName, 'r');
                $blobClient->createBlockBlob($container, $blobName, $content);
                
                $uploadedFiles[] = $blobName;
            }
        }
    }

    $images_json = json_encode($uploadedFiles);

    // 6b. INSERT INTO pr_appeal_items
    // Mapping form 'explanation' to schema 'remarks'
    // Note: Ensure you ran the ALTER TABLE commands to add 'builder_answer' and 'image_paths'
    $sql_item = "INSERT INTO pr_appeal_items 
                 (appeal_id, question_id, remarks, builder_answer, image_paths) 
                 VALUES (?, ?, ?, ?, ?)";
    
    $params_item = [
        $appeal_id,
        $qid,
        $explanation, 
        $answer,      
        $images_json  
    ];

    $stmt_item = sqlsrv_query($conn, $sql_item, $params_item);
    if ($stmt_item === false) {
        die(print_r(sqlsrv_errors(), true));
    }
}

// ---------------------------
// 7. FINISH & REDIRECT
// ---------------------------
sqlsrv_close($conn);

// Trigger Email Notification
$sendEmailUrl = "https://eventsprguide-fxgqhpcsgeamcyh7.southeastasia-01.azurewebsites.net/pr-feedback/send_appeal_email.php?pr_id=" . urlencode($pr_id);
@file_get_contents($sendEmailUrl);

header("Location: pr_feedback.php?pr_id=" . urlencode($pr_id) . "&appeal_submitted=true");
exit;
?>
