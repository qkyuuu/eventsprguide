<?php
header('Content-Type: application/json');

// 1. Get PR ID
$pr_id = $_POST['pr_id'] ?? $_GET['pr_id'] ?? null;
if (!$pr_id) {
    echo json_encode(['success' => false, 'message' => 'PRID is required.']);
    exit;
}

// 2. Azure SQL Connection (Matches pr_feedback.php)
$connectionOptions = ["Database" => "events-pr-db", "Uid" => "qmsadmin", "PWD" => "Codegenqms!", "Encrypt" => true];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// 3. Fetch Feedback Data
$sql = "SELECT * FROM pr_submissions WHERE pr_id = ?";
$stmt = sqlsrv_query($conn, $sql, array($pr_id));
$feedback = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$feedback) {
    echo json_encode(['success' => false, 'message' => 'Feedback record not found.']);
    exit;
}

// 4. Format Names (Recipient = Builder, Sender = Reviewer)
$builderName = ucwords(strtolower($feedback['builder_name'] ?? 'Builder'));
$reviewerName = ucwords(strtolower($feedback['peer_reviewer_name'] ?? 'Reviewer'));
$taskName = $feedback['task_name'] ?? 'Task';

// 5. Build HTML Email Body
$emailBody = "<html><body style='font-family: Arial, sans-serif;'>";
$emailBody .= "<h3>Hi $builderName,</h3>";
$emailBody .= "<p>Your task <b>$taskName</b> has been reviewed by <b>$reviewerName</b>.</p>";
$emailBody .= "<p>You can view the full details and images by clicking the link below:</p>";
$emailBody .= "<p><a href='https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=$pr_id' style='background:#0078d4; color:white; padding:10px; text-decoration:none; border-radius:5px;'>View Feedback</a></p>";
$emailBody .= "<p>Regards,<br><b>$reviewerName</b></p>";
$emailBody .= "</body></html>";

// 6. Trigger Power Automate
$flowUrl = 'https://default10f787270c1845afb9ee97e94fd5bc.d8.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/a4121885fc0243a1a3ec9ffe0d57c42b/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=_N3ad7Adnjiw-DNpQzNQda7f80ExDkpeVH4U4IfTPK8';

$data = [
    "recipient_email" => trim($feedback['builder_email'] ?? 'v-jopastoral@microsoft.com'),
    "recipient_name"  => $builderName,
    "subject"         => "Peer Review Feedback: $pr_id (From: $reviewerName)",
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

// 7. Success Response
if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode(['success' => true, 'message' => "Email sent to $builderName!"]);
} else {
    echo json_encode(['success' => false, 'message' => "Flow Error: $httpCode"]);
}
?>
