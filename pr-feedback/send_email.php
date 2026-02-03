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
    "Uid" => "qmsadmin",
    "PWD" => "Codegenqms!",
    "Encrypt" => true
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database connection failed.', 
        'error' => sqlsrv_errors()
    ]);
    exit;
}

// ---------------------------
// 3. Fetch Feedback Data
// ---------------------------
$sql = "SELECT * FROM pr_submissions WHERE pr_id = ?";
$stmt = sqlsrv_query($conn, $sql, [$pr_id]);
$feedback = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$feedback) {
    echo json_encode(['success' => false, 'message' => "PR ID $pr_id not found in Azure database."]);
    exit;
}

// ---------------------------
// 4. Fetch Questions & Answers
// ---------------------------
$answers = !empty($feedback['answers']) ? json_decode($feedback['answers'], true) : [];
$questions_result = sqlsrv_query($conn, "SELECT * FROM questions");

// ---------------------------
// 5. Format Names & Task Info
// ---------------------------
$builderName = ucwords(strtolower($feedback['builder_name'] ?? 'Builder'));
$reviewerName = ucwords(strtolower($feedback['peer_reviewer_name'] ?? 'Reviewer'));
$taskName = $feedback['task_name'] ?? 'Task';

// ---------------------------
// 6. Build HTML Email Body
// ---------------------------
$emailBody = "<html><body style='font-family: Arial, sans-serif; color: #333;'>";
$emailBody .= "<div style='background: #0078d4; color: white; padding: 15px;'><h2>New Peer Review Feedback</h2></div>";
$emailBody .= "<p>Hi <strong>$builderName</strong>,</p>";
$emailBody .= "<p>Your task <strong>$taskName</strong> has been reviewed by <strong>$reviewerName</strong>.</p>";
$emailBody .= "<h4>Review Summary:</h4><ul>";

while ($question = sqlsrv_fetch_array($questions_result, SQLSRV_FETCH_ASSOC)) {
    $qid = $question['question_id'];
    $answerKey = 'q' . $qid;
    if (isset($answers[$answerKey]) && strtolower($answers[$answerKey]) === 'applicable') {
        $remarks = $answers['remarks'.$qid] ?? 'No remarks provided';
        $emailBody .= "<li><strong>" . htmlspecialchars($question['question_text']) . "</strong>: " . htmlspecialchars($remarks) . "</li>";
    }
}

$emailBody .= "</ul>";
$emailBody .= "<p><a href='https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=" . urlencode($pr_id) . "' style='display:inline-block; background:#0078d4; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>View Full Feedback & Images</a></p>";
$emailBody .= "<p>Regards,<br><strong>$reviewerName</strong></p>";
$emailBody .= "</body></html>";

// ---------------------------
// 7. Trigger Power Automate Flow
// ---------------------------
$flowUrl = 'https://default10f787270c1845afb9ee97e94fd5bc.d8.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/62469676a4f44d61b22674cd7e33b2e0/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=_pysnZPyPOIj5zbQq0chYqOrtLewwi-UCND9aAJvNEE';

// Map PHP data to the flow parameters
$data = [
    "ToEmail"     => trim($feedback['builder_email'] ?? 'v-jopastoral@microsoft.com'),
    "SubjectText" => "Peer Review Feedback: $pr_id (From: $reviewerName)",
    "BodyText"    => $emailBody
];

$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// cURL POST request
$ch = curl_init($flowUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($jsonData)
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ---------------------------
// 8. Return JSON response
// ---------------------------
if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode([
        'success' => true,
        'message' => "Email triggered successfully for $builderName!"
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => "Flow Error: $httpCode",
        'debug_response' => $response,
        'curl_error' => $curlError
    ]);
}
?>
