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

// GitHub RAW base path (for static images)
$githubImg = 'https://raw.githubusercontent.com/qkyuuu/eventsprguide/main/img/';
// Azure Blob base path (for uploaded PR images)
$azureBlobBase = 'https://eventsprimagestore.blob.core.windows.net/pr-images/';

// ---------------------------
// 6. Build HTML Email Body
// ---------------------------
$emailBody = '<html>
<head>
    <meta charset="UTF-8">
    <style>
        .MsoNormal { margin: 0; padding: 0; }
    </style>
</head>
<body style="font-family:\'Aptos\',\'Segoe UI\',sans-serif; margin:0; padding:0; background-color:#e3e3e3;">

<table width="100%" bgcolor="#e3e3e3" cellpadding="0" cellspacing="0" style="width:100.0%; background:#E3E3E3;">
    <tr>
        <td align="center">
            <table width="1000" bgcolor="#ffffff" cellpadding="0" cellspacing="0" style="width:750.0pt; background:white; border-collapse:collapse;">
                
                <tr><td bgcolor="#e2e2e2" height="15" style="background:#E2E2E2;">&nbsp;</td></tr>

                <tr>
                    <td style="padding:11.25pt 11.25pt 11.25pt 11.25pt;" align="right">
                        <span style="font-size:8.0pt; font-family:\'Aptos\',sans-serif; color:black;">
                            <em>If there are problems with how this message is displayed, click here to view it in a web browser</em>
                        </span>
                    </td>
                </tr>

                <tr>
                    <td align="center">
                        <img src="' . $githubImg . rawurlencode('Header.jpg') . '" width="100%" style="display:block; border:0; width:100%;" alt="Header">
                    </td>
                </tr>

                <tr>
                    <td style="padding:3.75pt 15.0pt 3.75pt 15.0pt; text-align:center;">
                        <b style="font-size:25.0pt; color:#071952;">Feedback Received</b>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22.5pt 30.0pt 7.5pt 30.0pt; font-size:11pt; color:black;">
                        <p>Dear ' . htmlspecialchars($builderName) . ',</p>
                        <p>Your task has been reviewed by <strong>' . htmlspecialchars($reviewerName) . '</strong>. Please see the details below.</p>
                        
                        <p style="margin-top:15pt;">
                            <strong style="font-size:14.0pt; color:#192F75;">' . htmlspecialchars($taskNameShort) . '</strong><br>
                            <span style="color:#192F75;">PRID: <a href="#" style="color:#192F75; text-decoration:none;">' . htmlspecialchars($pr_id) . '</a></span>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:7.5pt 30.0pt 15.0pt 30.0pt;">
                        <img src="https://eventsprguide.infinityfree.me/img/Divider.png" width="100%" style="display:block; width:100%;" alt="Divider">
                    </td>
                </tr>

                <tr>
                    <td style="padding:0pt 30.0pt 15.0pt 30.0pt;">
                        <table width="100%" cellpadding="0" cellspacing="0">';

// ---------------------------
// QUESTIONS LOOP (Styling Copy)
// ---------------------------
foreach ($questions as $qid => $qText) {
    $answerKey = 'q' . $qid;

    if (!isset($answers[$answerKey]) || strtolower($answers[$answerKey]) !== 'applicable') {
        continue;
    }

    $remarks  = $answers['remarks' . $qid] ?? 'No remarks provided';
    $fatality = $answers['fatality' . $qid] ?? 'Not specified';

    $fatalityDisplay = ($fatality === 'fatal')
        ? "<span style='color:red;'>Fatal Error</span>"
        : (($fatality === 'nonFatal') ? "Non-Fatal Error" : "Not specified");

    $emailBody .= '
    <tr>
        <td style="padding-bottom:11.25pt;">
            <table width="100%" bgcolor="#F1F4F9" cellpadding="15" cellspacing="0" style="background:#F1F4F9; border-radius:8px; border-collapse:collapse;">
                <tr>
                    <td>
                        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:11pt;">
                            <tr><td width="15%" valign="top"><strong>Question:</strong></td><td width="85%">' . htmlspecialchars($qText) . '</td></tr>
                            <tr><td valign="top"><strong>Answer:</strong></td><td>Applicable</td></tr>
                            <tr><td valign="top"><strong>Fatality:</strong></td><td>' . $fatalityDisplay . '</td></tr>
                            <tr><td valign="top"><strong>Remarks:</strong></td><td>' . htmlspecialchars($remarks) . '</td></tr>
                        </table>';

    // Image logic remains unchanged as requested
    $qImages = $images[$answerKey] ?? [];
    if (!empty($qImages)) {
        $emailBody .= '<table width="100%" cellpadding="5" cellspacing="0" style="margin-top:10pt;"><tr>';
        $count = 0;
        foreach ($qImages as $img) {
            $imgUrl = $azureBlobBase . rawurlencode($img);
            $emailBody .= '
            <td width="33%" align="center" valign="top">
                <img src="' . $imgUrl . '" width="100%" style="display:block; border:0;" alt="Proof">
            </td>';
            $count++;
            if ($count % 3 === 0) { $emailBody .= '</tr><tr>'; }
        }
        $emailBody .= '</tr></table>';
    } else {
        $emailBody .= '<p style="color:blue; font-size:10pt; margin-top:10pt; font-family:Arial,sans-serif;">No images for this question.</p>';
    }

    $emailBody .= '</td></tr></table></td></tr>';
}

$emailBody .= '
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:7.5pt 30.0pt 7.5pt 30.0pt; font-size:11pt;">
                        How would you like to proceed with this peer review?
                    </td>
                </tr>

                <tr>
                    <td style="padding:0in 0in 15.0pt 0in;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="15%"></td>
                                <td width="30%" align="center" bgcolor="#28A745" style="background:#28A745; padding:5pt;">
                                    <a href="https://eventsprguide.azurewebsites.net/accept_review.php?pr_id=' . urlencode($pr_id) . '" style="color:white; text-decoration:none; font-weight:bold; display:block;">Accept</a>
                                </td>
                                <td width="10%"></td>
                                <td width="30%" align="center" bgcolor="#DC3545" style="background:#DC3545; padding:5pt;">
                                    <a href="https://eventsprguide.azurewebsites.net/appeal_review.php?pr_id=' . urlencode($pr_id) . '" style="color:white; text-decoration:none; font-weight:bold; display:block;">Appeal</a>
                                </td>
                                <td width="15%"></td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td>
                        <img src="https://eventsprguide.infinityfree.me/img/Footer.png" width="100%" style="display:block; width:100%;" alt="Footer">
                    </td>
                </tr>

            </table>
        </td>
    </tr>
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
