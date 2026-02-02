<?php
// ------------------- Get PR ID -------------------
$pr_id = $_POST['pr_id'] ?? $_GET['pr_id'] ?? null;
if (!$pr_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'PRID is required.']);
    exit;
}

// ------------------- Azure SQL Database Connection -------------------
// Matches your pr_feedback.php connection exactly
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

// ------------------- Decode Answers & Images -------------------
$answers = !empty($feedback['answers']) ? json_decode($feedback['answers'], true) : [];
$images  = !empty($feedback['image_paths']) ? json_decode($feedback['image_paths'], true) : [];

// ------------------- Fetch Questions -------------------
$questions_result = sqlsrv_query($conn, "SELECT * FROM questions");
$questions = [];
while ($row = sqlsrv_fetch_array($questions_result, SQLSRV_FETCH_ASSOC)) {
    $questions[$row['question_id']] = $row['question_text'];
}

// ------------------- Capitalize Names & Shorten Task -------------------
$builderName = ucwords(strtolower($feedback['builder_name'] ?? 'Builder'));
$reviewerName = ucwords(strtolower($feedback['peer_reviewer_name'] ?? 'Reviewer'));

$originalTaskName = $feedback['task_name'] ?? 'Unknown Task';
$taskNameShort = (strlen($originalTaskName) > 50) ? substr($originalTaskName, 0, 47) . '...' : $originalTaskName;

// ------------------- Build Email Body (HTML) -------------------
$emailBody = '<html><body style="font-family:Arial,sans-serif;">';
$emailBody .= "<h2>Feedback for Task: $taskNameShort</h2>";
$emailBody .= "<p>Hi <strong>$builderName</strong>, your task has been reviewed by <strong>$reviewerName</strong>.</p>";
$emailBody .= "<ul>";

foreach ($questions as $qid => $qText) {
    $answerKey = 'q' . $qid;
    if (isset($answers[$answerKey]) && strtolower($answers[$answerKey]) === 'applicable') {
        $remarks = $answers['remarks' . $qid] ?? 'No remarks';
        $emailBody .= "<li><strong>$qText</strong>: $remarks</li>";
    }
}

$emailBody .= "</ul>";
$emailBody .= '<p><a href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) . '">Click here to view full feedback and images</a></p>';
$emailBody .= '</body></html>';

// ------------------- POWER AUTOMATE TRIGGER -------------------

$flowUrl = 'https://default10f787270c1845afb9ee97e94fd5bc.d8.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/a4121885fc0243a1a3ec9ffe0d57c42b/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=_N3ad7Adnjiw-DNpQzNQda7f80ExDkpeVH4U4IfTPK8';

$data = [
    "recipient_email" => $feedback['builder_email'] ?? 'v-jopastoral@microsoft.com',
    "recipient_name"  => $builderName,
    "subject"         => "Peer Review Feedback: $taskNameShort (PRID: $pr_id)",
    "email_body"      => $emailBody
];

$ch = curl_init($flowUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ------------------- Final JSON Response -------------------
header('Content-Type: application/json');

if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode(['success' => true, 'message' => "Email triggered successfully via Power Automate!"]);
} else {
    echo json_encode(['success' => false, 'message' => "Flow failed (Code: $httpCode)", 'debug' => $response]);
}
?>
