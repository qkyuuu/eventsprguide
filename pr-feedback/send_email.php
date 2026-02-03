<?php
// 1. Set headers for JSON response and enable error reporting for debugging
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 2. Get PR ID from POST or GET
$pr_id = $_POST['pr_id'] ?? $_GET['pr_id'] ?? null;
if (!$pr_id) {
    echo json_encode(['success' => false, 'message' => 'PRID is required.']);
    exit;
}

// 3. Azure SQL Database Connection (Matches pr_feedback.php exactly)
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

// 4. Fetch Feedback Data
$sql = "SELECT * FROM pr_submissions WHERE pr_id = ?";
$stmt = sqlsrv_query($conn, $sql, array($pr_id));
$feedback = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$feedback) {
    echo json_encode(['success' => false, 'message' => "PRID $pr_id not found in Azure database."]);
    exit;
}

// 5. Fetch Questions and Decode Answers (to include in email body)
$answers = !empty($feedback['answers']) ? json_decode($feedback['answers'], true) : [];
$questions_result = sqlsrv_query($conn, "SELECT * FROM questions");
$questions = [];
while ($row = sqlsrv_fetch_array($questions_result, SQLSRV_FETCH_ASSOC)) {
    $questions[$row['question_id']] = $row['question_text'];
}

// 6. Format Names and Task Information
$builderName = ucwords(strtolower($feedback['builder_name'] ?? 'Builder'));
$reviewerName = ucwords(strtolower($feedback['peer_reviewer_name'] ?? 'Reviewer'));
$taskName = $feedback['task_name'] ?? 'Task';

// 7. Build HTML Email Body
$emailBody = "<html><body style='font-family: Arial, sans-serif; color: #333;'>";
$emailBody .= "<div style='background: #0078d4; color: white; padding: 15px;'><h2>New Peer Review Feedback</h2></div>";
$emailBody .= "<p>Hi <strong>$builderName</strong>,</p>";
$emailBody .= "<p>Your task <strong>$taskName</strong> has been reviewed by <strong>$reviewerName</strong>.</p>";
$emailBody .= "<h4>Review Summary:</h4><ul>";

foreach ($questions as $qid => $qText) {
    $answerKey = 'q' . $qid;
    if (isset($answers[$answerKey]) && strtolower($answers[$answerKey]) === 'applicable') {
        $remarks = $answers['remarks' . $qid] ?? 'No remarks provided';
        $emailBody .= "<li><strong>" . htmlspecialchars($qText) . "</strong>: " . htmlspecialchars($remarks) . "</li>";
    }
}

$emailBody .= "</ul>";
$emailBody .= "<p><a href='https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=" . urlencode($pr_id) . "' style='display:inline-block; background:#0078d4; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>View Full Feedback & Images</a></p>";
$emailBody .= "<p>Regards,<br><strong>$reviewerName</strong></p>";
$emailBody .= "</body></html>";

// 8. POWER AUTOMATE TRIGGER
// This URL matches the signature in your screenshot
$flowUrl = 'https://default10f787270c1845afb9ee97e94fd5bc.d8.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/a4121885fc0243a1a3ec9ffe0d57c42b/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=_N3ad7Adnjiw-DNpQzNQda7f80ExDkpeVH4U4IfTPK8';

// Keys below must match your "Request Body JSON Schema" in Power Automate
$data = [
    "ToEmail"     => trim($feedback['builder_email'] ?? 'v-jopastoral@microsoft.com'),
    "SubjectText" => "Peer Review Feedback: $pr_id (From: $reviewerName)",
    "BodyText"    => $emailBody
];


$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// 9. Send Request via cURL
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

// 10. Final Response back to the Webpage
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
