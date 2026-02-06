<?php
error_reporting(E_ALL);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;

/**
 * Show loader while Azure SQL wakes up
 */
function showDbWakeLoader() {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Waking Database…</title>
        <meta http-equiv="refresh" content="30">
        <style>
            body {
                margin: 0;
                height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #0f172a;
                font-family: Arial, sans-serif;
            }

            .loader {
                display: flex;
                align-items: center;
                justify-content: center;
                flex-direction: column;
                gap: 12px;
            }

            .loading-text {
                color: white;
                font-size: 14pt;
                font-weight: 600;
            }

            .dot {
                animation: blink 1.5s infinite;
            }
            .dot:nth-child(2) { animation-delay: .3s; }
            .dot:nth-child(3) { animation-delay: .6s; }

            .loading-bar-background {
                width: 220px;
                height: 30px;
                padding: 5px;
                background: #212121;
                border-radius: 15px;
                box-shadow: inset -2px 2px 4px #0c0c0c;
            }

            .loading-bar {
                height: 20px;
                width: 0%;
                background: linear-gradient(0deg, #de4a0f, #f9c74f);
                border-radius: 10px;
                animation: loading 4s ease-out infinite;
            }

            @keyframes loading {
                0% { width: 0; }
                80% { width: 100%; }
                100% { width: 100%; }
            }

            @keyframes blink {
                0%,100% { opacity: 0; }
                50% { opacity: 1; }
            }
        </style>
    </head>
    <body>
        <div class="loader">
            <div class="loading-text">
                Database is waking up
                <span class="dot">.</span><span class="dot">.</span><span class="dot">.</span>
            </div>
            <div class="loading-bar-background">
                <div class="loading-bar"></div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

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
    $errors = sqlsrv_errors();
    $isColdStart = false;

    foreach ($errors as $err) {
        if (
            in_array($err['SQLSTATE'], ['HYT00', '08001']) ||
            strpos($err['message'], 'Login timeout expired') !== false
        ) {
            $isColdStart = true;
            break;
        }
    }

    if ($isColdStart) {
        showDbWakeLoader();
        exit;
    }

    // Not a cold start → real error
    die(print_r($errors, true));
}

// ---------------------------
// 2. Azure Blob Connection
// ---------------------------
$accountName = getenv('AZURE_STORAGE_ACCOUNT');
$accountKey  = getenv('AZURE_STORAGE_KEY');
$container   = getenv('AZURE_STORAGE_CONTAINER');

$connectionString =
    "DefaultEndpointsProtocol=https;" .
    "AccountName=$accountName;" .
    "AccountKey=$accountKey";

$blobClient = BlobRestProxy::createBlobService($connectionString);

// ---------------------------
// 3. Collect Answers
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
// 4. Upload Images to Azure Blob
// ---------------------------
$imagePaths = [];

foreach ($_FILES as $inputName => $fileArray) {
    if (preg_match('/^image_q(\d+)$/', $inputName, $matches)) {
        $qId = $matches[1];
        $imagePaths['q'.$qId] = [];

        for ($i = 0; $i < count($fileArray['name']); $i++) {
            if ($fileArray['error'][$i] === UPLOAD_ERR_OK) {

                $originalName = basename($fileArray['name'][$i]);
                $blobName = uniqid() . '_' . $originalName;

                $content = fopen($fileArray['tmp_name'][$i], 'r');

                $blobClient->createBlockBlob(
                    $container,
                    $blobName,
                    $content
                );

                $imagePaths['q'.$qId][] = $blobName;
            }
        }
    }
}

// ---------------------------
// 5. Generate PR ID
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
// 6. Insert into Database
// ---------------------------
$params = [
    $next_pr_id,
    'v-jopastoral@microsoft.com',
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
// 7. Redirect
// ---------------------------
header("Location: pr-feedback/pr_feedback.php?pr_id=$next_pr_id&success=true");
exit;
