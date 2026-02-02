<?php
// ------------------- Get PR ID -------------------
$pr_id = $_POST['pr_id'] ?? $_GET['pr_id'] ?? null;
if (!$pr_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'PRID is required.']);
    exit;
}

// ------------------- Azure SQL Database Connection -------------------
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid" => "qmsadmin",
    "PWD" => "Codegenqms!",
    "Encrypt" => true
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// ------------------- Fetch Feedback -------------------
$sql = "SELECT * FROM pr_submissions WHERE pr_id = ?";
$stmt = sqlsrv_query($conn, $sql, array($pr_id));
$feedback = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$feedback) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Feedback record not found.']);
    exit;
}

// ------------------- Decode Answers -------------------
$answers = !empty($feedback['answers']) ? json_decode($feedback['answers'], true) : [];

// ------------------- Fetch Questions -------------------
$questions_result = sqlsrv_query($conn, "SELECT * FROM questions");
$questions = [];
while ($row = sqlsrv_fetch_array($questions_result, SQLSRV_FETCH_ASSOC)) {
    $questions[$row['question_id']] = $row['question_text'];
}

// ------------------- Format Names & Task -------------------
// Recipient is the Builder
$builderName = ucwords(strtolower($feedback['builder_name'] ?? 'Builder'));
// Sender is the Peer Reviewer
$reviewerName = ucwords(strtolower($feedback['peer_reviewer_name'] ?? 'Reviewer'));

$originalTaskName = $feedback['task_name'] ?? 'Unknown Task';
$taskNameShort = (strlen($originalTaskName) > 50) ? substr($originalTaskName, 0, 47) . '...' : $originalTaskName;

// ------------------- Build Email Body (HTML) -------------------
$emailBody = '<html><body style="font-family:Arial,sans-serif; color: #333;">';
$emailBody .= "<div style='background-color: #f8f9fa; padding: 20px; border-bottom: 3px solid #0078d4;'>";
$emailBody .= "<h2 style='color: #0078d4; margin-top: 0;'>New Peer Review Feedback</h2>";
$emailBody .= "</div>";
$emailBody .= "<div style='padding: 20px;'>";
$emailBody .= "<p>Hi <strong>$builderName</strong>,</p>";
$emailBody .= "<p>Your task <strong>$taskNameShort</strong> has been reviewed by <strong>$reviewerName</strong>.</p>";
$emailBody .= "<hr style='border: 0; border-top: 1px solid #eee;'>";
$emailBody .= "<h3>Review Summary:</h3>";
$emailBody .= "<ul>";

foreach ($questions as $qid => $qText) {
    $answerKey = 'q' . $qid;
    if (isset($answers[$answerKey]) && strtolower($answers[$answerKey]) === 'applicable') {
        $remarks = $answers['remarks' . $qid] ?? 'No remarks provided';
        $emailBody .= "<li style='margin-bottom: 10px;'><strong>" . htmlspecialchars($qText) . "</strong><br>";
        $emailBody .= "<span style='color: #666;'>Remarks: " . htmlspecialchars($remarks) . "</span></li>";
    }
}

$emailBody .= "</ul>";
$emailBody .= "<p style='margin-top: 30px;'>";
$emailBody .= "<a href='https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=" . urlencode($pr_id) . "' ";
$emailBody .= "style='background-color: #0078d4; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>View Full Feedback & Images</a>";
$emailBody .= "</p>";
$emailBody .= "<p style='font-size: 0.9em; color: #888; margin-top: 40px;'>Regards,<br><strong>$reviewerName</strong></p>";
$emailBody .= "</div></body></html>";

// ------------------- POWER AUTOMATE TRIGGER -------------------

$flowUrl = 'https://default10f787270c1845afb9ee97e94fd5bc.d8.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/a4121885fc0243a1a3ec9ffe0d57c42b/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=_N3ad7Adnjiw-DNpQzNQda7f80ExDkpeVH4U4IfTPK8';

$data = [
    "recipient_email" => trim($feedback['builder_email'] ?? 'v-jopastoral@microsoft.com'),
    "recipient_name"  => $builderName, // To the Builder
    "subject"         => "Peer Review Feedback: $taskNameShort (From: $reviewerName)",
    "email_body"      => $emailBody
];

$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
curl_close($ch);

// ------------------- Final JSON Response -------------------
header('Content-Type: application/json');

if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode(['success' => true, 'message' => "Feedback sent to $builderName!"]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => "Power Automate Error (Code: $httpCode)", 
        'debug' => $response
    ]);
}
?>
