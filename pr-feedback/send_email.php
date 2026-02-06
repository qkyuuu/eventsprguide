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
$emailBody = '<html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif; margin:0; padding:0;">

<table width="100%" bgcolor="#e3e3e3" cellpadding="0" cellspacing="0">
<tr><td align="center">

<table width="1000" bgcolor="#ffffff" cellpadding="0" cellspacing="0">

<!-- TOP BAR -->
<tr><td bgcolor="#e2e2e2" height="15">&nbsp;</td></tr>

<!-- VIEW IN BROWSER -->
<tr>
<td style="padding:15px; font-size:10.5px; line-height:12px;" align="right">
<em>If there are problems with how this message is displayed, please view it in a browser.</em>
</td>
</tr>

<!-- HEADER IMAGE -->
<tr>
<td align="center">
<img src="' . $githubImg . rawurlencode('Header.jpg') . '" width="100%" style="display:block; border:0; width:100%;" alt="Header">
</td>
</tr>

<tr><td height="20">&nbsp;</td></tr>

<!-- TITLE -->
<tr>
<td style="color:#071952; font-size:25pt; font-weight:700; text-align:center;">
Feedback Received
</td>
</tr>

<!-- INTRO -->
<tr>
<td style="padding:30px 40px 10px 40px; font-size:12pt;">
<p>Dear <strong>' . htmlspecialchars($builderName) . '</strong>,</p>
<p>Your task has been reviewed by <strong>' . htmlspecialchars($reviewerName) . '</strong>.</p>

<p style="margin-top:15pt;">
 <strong style="font-size:14.0pt; color:#192F75;">' . htmlspecialchars($taskNameShort) . '</strong><br>
<span style="color:#192F75;">PRID: <a href="#" style="color:#192F75; text-decoration:none;">' . htmlspecialchars($pr_id) . '</a></span>
</p>
</td>
</tr>

<!-- DIVIDER -->
<tr>
                    <td style="padding:7.5pt 30.0pt 15.0pt 30.0pt;">
                        <img src="' . $githubImg . rawurlencode('Divider.png') . '" width="100%" style="display:block; width:100%;" alt="Divider">
                    </td>
                </tr>

<tr>
<td style="padding:20px 40px; font-size:12pt;">
<table width="100%" cellpadding="0" cellspacing="0">';

// ---------------------------
// QUESTIONS LOOP
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
    <td style="padding-bottom:20px;">
    <table width="100%" bgcolor="#f1f4f9" cellpadding="20" cellspacing="0" style="border-radius:8px;">
        <tr><td style="padding:5px 10px !important; margin:0;"><strong>Question:</strong> ' . htmlspecialchars($qText) . '</td></tr>
        <tr><td style="padding:5px 10px !important; margin:0;"><strong>Answer:</strong> Applicable</td></tr>
        <tr><td style="padding:5px 10px !important; margin:0;"><strong>Fatality:</strong> ' . $fatalityDisplay . '</td></tr>
        <tr><td style="padding:5px 10px !important; margin:0;"><strong>Remarks:</strong> ' . htmlspecialchars($remarks) . '</td></tr>';
    // ---------------------------
    // IMAGES (Azure Blob)
    // ---------------------------
    $qImages = $images[$answerKey] ?? [];

    if (!empty($qImages)) {
        $emailBody .= '<tr><td><table width="100%" cellpadding="5" cellspacing="0"><tr>';
        $count = 0;

        foreach ($qImages as $img) {
            $imgUrl = $azureBlobBase . rawurlencode($img);

            $emailBody .= '
            <td width="33%" align="center" valign="top">
                <img src="' . $imgUrl . '" width="100%" style="border-radius:6px; display:block; border:0; outline:none; text-decoration:none;" alt="PR Image">
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

$emailBody .= '
</table>
</td>
</tr>

<!-- ACTION TEXT -->
<tr>
<td style="padding:10px 40px; font-size:12pt;">
How would you like to proceed with this peer review?
</td>
</tr>

<!-- BUTTONS -->
<tr>
<td align="center" style="padding:20px;">
<table width="100%" cellpadding="0" cellspacing="0">
<tr>
<td width="15%"></td>
<td width="30%" align="center" bgcolor="#28a745" style="padding:10px; border-radius:6px;">
<a href="https://eventsprguide-fxgqhpcsgeamcyh7.southeastasia-01.azurewebsites.net/pr-feedback/accept_review.php?pr_id=' . urlencode($pr_id) . '" style="color:#fff; text-decoration:none; display:block;">Accept</a>
</td>
<td width="10%"></td>
<td width="30%" align="center" bgcolor="#dc3545" style="padding:10px; border-radius:6px;">
<a href="https://eventsprguide-fxgqhpcsgeamcyh7.southeastasia-01.azurewebsites.net/pr-feedback/appeal_review.php?pr_id=' . urlencode($pr_id) . '" style="color:#fff; text-decoration:none; display:block;">Appeal</a>
</td>
<td width="15%"></td>
</tr>
</table>
</td>
</tr>

<!-- FOOTER -->
<tr>
                    <td>
                        <img src="' . $githubImg . rawurlencode('Footer.png') . '" width="100%" style="display:block; width:100%;" alt="Footer">
                    </td>
                </tr>

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
