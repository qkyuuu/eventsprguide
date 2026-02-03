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

// Build a questions array for easier looping
$questions = [];
while ($row = sqlsrv_fetch_array($questions_result, SQLSRV_FETCH_ASSOC)) {
    $questions[$row['question_id']] = $row['question_text'];
}

// Images (if any)
$images = !empty($feedback['image_paths']) ? json_decode($feedback['image_paths'], true) : [];

// ---------------------------
// 5. Format Names & Task Info
// ---------------------------
$builderName = ucwords(strtolower($feedback['builder_name'] ?? 'Builder'));
$reviewerName = ucwords(strtolower($feedback['peer_reviewer_name'] ?? 'Reviewer'));
$taskName = $feedback['task_name'] ?? 'Task';
$taskNameShort = mb_strimwidth($taskName, 0, 50, '...');

// ---------------------------
// 6. Build HTML Email Body
// ---------------------------
$emailBody = '<html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif; margin:0; padding:0;">';
$emailBody .= '
<table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" bgcolor="#e3e3e3">
  <tr><td align="center">
    <table width="1000" border="0" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF" align="center">
      <tr><td bgcolor="#e2e2e2">&nbsp;</td></tr>
      <tr><td style="padding:15px; font-size:10.5px" align="right">
        <a href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) . '" target="_blank" style="color:#000; text-decoration:none">
          <em>If there are problems with how this message is displayed, click here to view it in a web browser</em>
        </a>
      </td></tr>
      <tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Header.jpg" width="100%" alt="Header" /></td></tr>
      <tr><td>&nbsp;</td></tr>
      <tr><td style="color:#071952; font-size:25pt; padding:5px 20px; font-weight:700" align="center">Feedback Received</td></tr>
      <tr><td style="padding:30px 40px 10px 40px; color:#000; font-size:12pt">
        <p>Dear ' . htmlspecialchars($builderName) . ',</p>
        <p>Your task has been reviewed by <strong>' . htmlspecialchars($reviewerName) . '</strong>. Please see the details below.</p>
        <p style="font-size:14pt;color:#192f75"><strong>' . htmlspecialchars($taskNameShort) . '</strong><br/>
        <span style="font-size:12pt">PRID: <a href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) . '" target="_blank" style="color:#192f75">' . htmlspecialchars($pr_id) . '</a></span></p>
      </td></tr>
      <tr><td style="padding:10px 40px 20px 40px; color:#000; font-size:12pt">
        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse;">
          <tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Divider.png" width="100%" alt="Divider" /></td></tr>';

// Loop through each question
foreach ($questions as $qid => $qText) {
    $answerKey = 'q' . $qid;
    if (isset($answers[$answerKey]) && strtolower($answers[$answerKey]) === 'applicable') {
        $remarksKey = 'remarks' . $qid;
        $remarks = $answers[$remarksKey] ?? 'No remarks provided';
        $fatalityKey = 'fatality' . $qid;
        $fatality = $answers[$fatalityKey] ?? 'Not specified';
        $fatality_display = ($fatality === 'fatal') ? "<span style='color:red;'>Fatal Error</span>" : (($fatality === 'nonFatal') ? "Non-Fatal Error" : "Not specified");
        $qImages = $images[$answerKey] ?? [];

        // Outer wrapper table
        $emailBody .= '
          <tr>
            <td style="padding-bottom:15px;">
              <table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse; background-color:#f1f4f9; border-radius:8px;">
                <tr><td style="padding:20px; font-family:Arial, sans-serif; font-size:12pt; color:#000;">
                  <table width="100%" border="0" cellspacing="0" cellpadding="0">
                    <tr><td width="10%"><strong>Question:</strong></td><td width="90%">' . htmlspecialchars($qText) . '</td></tr>
                    <tr><td width="10%"><strong>Answer:</strong></td><td width="90%">' . htmlspecialchars($answers[$answerKey]) . '</td></tr>
                    <tr><td width="10%"><strong>Fatality:</strong></td><td width="90%">' . $fatality_display . '</td></tr>
                    <tr><td width="10%"><strong>Remarks:</strong></td><td width="90%">' . htmlspecialchars($remarks) . '</td></tr>
                  </table>';

        // Images
        if (!empty($qImages)) {
            $emailBody .= '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse; margin-bottom:10px;"><tr>';
            $count = 0;
            foreach ($qImages as $img) {
                $imgUrl = 'https://eventsprguide.infinityfree.me/uploads/' . str_replace(' ', '%20', $img);
                $emailBody .= '<td style="padding:5px; text-align:center; width:33%;">
                                  <img src="' . $imgUrl . '" width="100%" alt="Proof">
                               </td>';
                $count++;
                if ($count % 3 == 0) $emailBody .= '</tr><tr>';
            }
            $remaining = 3 - ($count % 3);
            if ($remaining < 3) for ($i = 0; $i < $remaining; $i++) $emailBody .= '<td>&nbsp;</td>';
            $emailBody .= '</tr></table>';
        } else {
            $emailBody .= '<p style="color:blue; margin:0;">No images for this question.</p>';
        }

        $emailBody .= '</td></tr></table></td></tr>';
    }
}

// Accept / Appeal buttons
$acceptUrl = 'https://eventsprguide.infinityfree.me/pr-feedback/accept_review.php?pr_id=' . urlencode($pr_id);
$appealUrl = 'https://eventsprguide.infinityfree.me/pr-feedback/appeal_review.php?pr_id=' . urlencode($pr_id);

$emailBody .= '
<tr><td style="padding:10px 20px; font-size:12pt">How would you like to proceed with this peer review?</td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="0">
<tr>
<td width="15%">&nbsp;</td>
<td width="30%" style="padding:5px 10px; background:#28a745" align="center">
<a href="' . $acceptUrl . '" style="color:#fff; text-decoration:none;">Accept</a>
</td>
<td width="10%">&nbsp;</td>
<td width="30%" style="padding:5px 10px; background:#dc3545" align="center">
<a href="' . $appealUrl . '" style="color:#fff; text-decoration:none;">Appeal</a>
</td>
<td width="15%">&nbsp;</td>
</tr>
</table>
</td></tr>
<tr><td>&nbsp;</td></tr>
<tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Footer.png" width="100%" alt="Footer"/></td></tr>
</table></td></tr></table>
</body></html>';

// ---------------------------
// 7. Trigger Power Automate Flow
// ---------------------------
$flowUrl = 'https://default10f787270c1845afb9ee97e94fd5bc.d8.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/62469676a4f44d61b22674cd7e33b2e0/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=_pysnZPyPOIj5zbQq0chYqOrtLewwi-UCND9aAJvNEE';

$data = [
    "ToEmail"     => trim($feedback['builder_email'] ?? 'v-jopastoral@microsoft.com'),
    "SubjectText" => "Peer Review Feedback: $pr_id (From: $reviewerName)",
    "BodyText"    => $emailBody
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
