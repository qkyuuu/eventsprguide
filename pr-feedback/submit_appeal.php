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
// 4. GET BUILDER EMAIL FROM pr_submissions
// ---------------------------
$sql_email = "SELECT builder_email FROM pr_submissions WHERE pr_id = ?";
$stmt_email = sqlsrv_query($conn, $sql_email, [$pr_id]);
$row = sqlsrv_fetch_array($stmt_email, SQLSRV_FETCH_ASSOC);

if (!$row) {
    die("PR Submission not found.");
}
$builder_email = $row['builder_email'];

// ---------------------------
// 5. INSERT INTO pr_appeals
// ---------------------------
// Using OUTPUT INSERTED to get the auto-increment ID in Azure SQL
$sql_appeal = "INSERT INTO pr_appeals (pr_id, builder_email) OUTPUT INSERTED.appeal_id VALUES (?, ?)";
$stmt_appeal = sqlsrv_query($conn, $sql_appeal, [$pr_id, $builder_email]);
$appeal_row = sqlsrv_fetch_array($stmt_appeal, SQLSRV_FETCH_ASSOC);
$appeal_id = $appeal_row['appeal_id'];

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
    $sql_item = "INSERT INTO pr_appeal_items 
                 (appeal_id, question_id, builder_answer, explanation, image_paths) 
                 VALUES (?, ?, ?, ?, ?)";
    
    $params_item = [
        $appeal_id,
        $qid,
        $answer,
        $explanation,
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

// Update this URL to your actual Azure Website URL
$sendEmailUrl = "https://eventsprguide-fxgqhpcsgeamcyh7.southeastasia-01.azurewebsites.net/pr-feedback/send_appeal_email.php?pr_id=" . urlencode($pr_id);
@file_get_contents($sendEmailUrl);

header("Location: pr_feedback.php?pr_id=" . urlencode($pr_id) . "&appeal_submitted=true");
exit;
?>
