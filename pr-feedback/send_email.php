<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------------------------
// 1. Get PR ID from POST or GET
// ---------------------------
$pr_id = $_POST['pr_id'] ?? $_GET['pr_id'] ?? null;
if (!$pr_id) {
    echo json_encode(['success' => false, 'message' => 'PR ID is required.']);
    exit;
}

// ---------------------------
// 2. Azure SQL Database Connection
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
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.',
        'error'   => sqlsrv_errors()
    ]);
    exit;
}

// ---------------------------
// 3. Fetch Feedback Data
// ---------------------------
$sql  = "SELECT * FROM pr_submissions WHERE pr_id = ?";
$stmt = sqlsrv_query($conn, $sql, [$pr_id]);
$feedback = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$feedback) {
    echo json_encode(['success' => false, 'message' => "PR ID $pr_id not found."]);
    exit;
}

// ---------------------------
// 4. Fetch Questions & Decode Data
// ---------------------------
$answers = !empty($feedback['answers'])
    ? json_decode($feedback['answers'], true)
    : [];

$images = !empty($feedback['image_paths'])
    ? json_decode($feedback['image_paths'], true)
    : [];

$questions_result = sqlsrv_query($conn, "SELECT * FROM questions");
$questions = [];
while ($row = sqlsrv_fetch_array($questions_result, SQLSRV_FETCH_ASSOC)) {
    $questions[$row['question_id']] = $row['question_text'];
}

// ---------------------------
// 5. Formatting
// ---------------------------
$builderName   = ucwords(strtolower($feedback['builder_name'] ?? 'Builder'));
$reviewerName  = ucwords(strtolower($feedback['peer_reviewer_name'] ?? 'Reviewer'));
$taskName      = $feedback['task_name'] ?? 'Task';
$taskNameShort = mb_strimwidth($taskName, 0, 60, '...');

// ✅ GitHub RAW base path (THIS FIXES IMAGES)
$githubUploadsBase = 'https://raw.githubusercontent.com/qkyuuu/eventsprguide/main/uploads/';

// ---------------------------
// 6. Build HTML Email Body
// ---------------------------
$emailBody = '<html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif; margin:0; padding:0;">
<table width="100%" bgcolor="#e3e3e3" cellpadding="0" cellspacing="0">
<tr><td align="center">
<table width="1000" bgcolor="#ffffff" cellpadding="0" cellspacing="0">

<tr><td style="padding:20px; text-align:center; font-size:26px; font-weight:bold; color:#071952;">
Feedback Received
</td></tr>

<tr><td style="padding:30px 40px; font-size:14px;">
<p>Dear <strong>' . htmlspecialchars($builderName) . '</strong>,</p>
<p>Your task has been reviewed by <strong>' . htmlspecialchars($reviewerName) . '</strong>.</p>
<p style="font-size:16px; color:#192f75;"><strong>' . htmlspecialchars($taskNameShort) . '</strong><br>
PRID: <strong>' . htmlspecialchars($pr_id) . '</strong></p>
</td></tr>

<tr><td style="padding:10px 40px;">
<table width="100%" cellpadding="0" cellspacing="0">';

// ---------------------------
// 7. Loop Questions
// ---------------------------
foreach ($questions as $qid => $qText) {
    $answerKey = 'q' . $qid;

    if (!isset($answers[$answerKey]) || strtolower($answers[$answerKey]) !== 'applicable') {
        continue;
    }

    $remarks   = $answers['remarks' . $qid] ?? 'No remarks provided';
    $fatality  = $answers['fatality' . $qid] ?? 'Not specified';

    $fatalityDisplay = ($fatality === 'fatal')
        ? "<span style='color:red;'>Fatal Error</span>"
        : (($fatality === 'nonFatal') ? "Non-Fatal Error" : "Not specified");

    $emailBody .= '
    <tr><td style="padding-bottom:20px;">
    <table width="100%" bgcolor="#f1f4f9" cellpadding="15" cellspacing="0" style="border-radius:8px;">
        <tr><td><strong>Question:</strong> ' . htmlspecialchars($qText) . '</td></tr>
        <tr><td><strong>Answer:</strong> Applicable</td></tr>
        <tr><td><strong>Fatality:</strong> ' . $fatalityDisplay . '</td></tr>
        <tr><td><strong>Remarks:</strong> ' . htmlspecialchars($remarks) . '</td></tr>';

    // ---------------------------
    // Images
    // ---------------------------
    $qImages = $images[$answerKey] ?? [];

    if (!empty($qImages)) {
        $emailBody .= '<tr><td><table width="100%" cellpadding="5"><tr>';
        $count = 0;

        foreach ($qImages as $img) {
            $imgUrl = $githubUploadsBase . rawurlencode($img);

            $emailBody .= '
            <td width="33%" align="center">
                <img src="' . $imgUrl . '" width="100%" style="border-radius:6px;">
            </td>';

            $count++;
            if ($count % 3 === 0) {
                $emailBody .= '</tr><tr>';
            }
        }

        $emailBody .= '</tr></table></td></tr>';
    } else {
        $emailBody .= '<tr><td style="color:#555;">No images for this question.</td></tr>';
    }

    $emailBody .= '</table></td></tr>';
}

// ---------------------------
// 8. Accept / Appeal Buttons
// ---------------------------
$acceptUrl = 'https://eventsprguide.azurewebsites.net/accept_review.php?pr_id=' . urlencode($pr_id);
$appealUrl = 'https://eventsprguide.azurewebsites.net/appeal_review.php?pr_id=' . urlencode($pr_id);

$emailBody .= '
<tr><td style="padding:30px; text-align:center;">
<a href="' . $acceptUrl . '" style="background:#28a745;color:#fff;padding:12px 25px;text-decoration:none;margin-right:20px;">Accept</a>
<a href="' . $appealUrl . '" style="background:#dc3545;color:#fff;padding:12px 25px;text-decoration:none;">Appeal</a>
</td></tr>

</table>
</td></tr>
</table>
</body></html>';

// ---------------------------
// 9. Trigger Power Automate Flow
// ---------------------------
$flowUrl = 'https://default10f787270c1845afb9ee97e94fd5bc.d8.environment.api.powerplatform.com/powerautomate/automations/direct/workflows/62469676a4f44d61b22674cd7e33b2e0/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=_pysnZPyPOIj5zbQq0chYqOrtLewwi-UCND9aAJvNEE';

$data = [
    "ToEmail"     => trim($feedback['builder_email']),
    "SubjectText" => "Peer Review Feedback: $pr_id",
    "BodyText"    => $emailBody
];

$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init($flowUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ---------------------------
// 10. Final Response
// ---------------------------
echo json_encode([
    'success' => $httpCode >= 200 && $httpCode < 300,
    'httpCode' => $httpCode
]);
