<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------------------------
// 1. Get PR ID
// ---------------------------
$pr_id = $_GET['pr_id'] ?? null;
if (!$pr_id) die("PR ID missing.");

// ---------------------------
// 2. Azure SQL Connection
// ---------------------------
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid"      => "qmsadmin",
    "PWD"      => "Codegenqms!",
    "Encrypt"  => true
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) die(print_r(sqlsrv_errors(), true));

// ---------------------------
// 3. Fetch PR Submission
// ---------------------------
$stmt = sqlsrv_query(
    $conn,
    "SELECT * FROM pr_submissions WHERE pr_id = ?",
    [$pr_id]
);

$feedback = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if (!$feedback) die("Invalid PR ID.");

// ---------------------------
// 4. Decode Answers
// ---------------------------
$answers = !empty($feedback['answers'])
    ? json_decode($feedback['answers'], true)
    : [];

// ---------------------------
// 5. Fetch Questions
// ---------------------------
$questions = [];
$qResult = sqlsrv_query($conn, "SELECT * FROM questions");
while ($q = sqlsrv_fetch_array($qResult, SQLSRV_FETCH_ASSOC)) {
    $questions[$q['question_id']] = $q['question_text'];
}

// ---------------------------
// 6. Fetch Latest Appeal
// ---------------------------
$stmt = sqlsrv_query(
    $conn,
    "SELECT TOP 1 appeal_id FROM pr_appeals WHERE pr_id = ? ORDER BY appeal_id DESC",
    [$pr_id]
);

$appealRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$appeal_id = $appealRow['appeal_id'] ?? null;

$appeal_items = [];

if ($appeal_id) {
    $stmt = sqlsrv_query(
        $conn,
        "SELECT * FROM pr_appeal_items WHERE appeal_id = ?",
        [$appeal_id]
    );

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $row['image_paths'] = !empty($row['image_paths'])
            ? json_decode($row['image_paths'], true)
            : [];
        $appeal_items[$row['question_id']] = $row;
    }
}

sqlsrv_close($conn);

// ---------------------------
// 7. Formatting
// ---------------------------
$builderName  = ucwords(strtolower($feedback['builder_name'] ?? 'Builder'));
$reviewerName = ucwords(strtolower($feedback['peer_reviewer_name'] ?? 'Reviewer'));
$taskName     = $feedback['task_name'] ?? '';
$taskShort    = mb_strimwidth($taskName, 0, 60, '...');

$azureBlobBase = 'https://eventsprimagestore.blob.core.windows.net/pr-images/';
$webBaseUrl    = 'https://eventsprguide-fxgqhpcsgeamcyh7.southeastasia-01.azurewebsites.net';
$githubImg     = 'https://raw.githubusercontent.com/qkyuuu/eventsprguide/main/img/';

// ---------------------------
// 8. Build Email Body (DESIGN UNCHANGED)
// ---------------------------
$emailBody = '<html><body style="font-family:Arial,sans-serif;">
<table width="100%" bgcolor="#e3e3e3"><tr><td align="center">
<table width="1000" bgcolor="#ffffff">

<tr><td align="right" style="padding:15px;font-size:10px">
<a href="'.$webBaseUrl.'/pr-feedback/pr_feedback.php?pr_id='.urlencode($pr_id).'">
<em>If this email displays incorrectly, click here</em></a>
</td></tr>

<tr><td><img src="'.$githubImg.'Header.jpg" width="100%"></td></tr>

<tr><td style="text-align:center;font-size:25pt;color:#071952;font-weight:bold">
Feedback Appeal Received
</td></tr>

<tr><td style="padding:30px;font-size:12pt">
<p>Dear '.$reviewerName.',</p>
<p><strong>'.$builderName.'</strong> has appealed your feedback.</p>

<p style="color:#192f75;font-size:14pt">
<strong>'.$taskShort.'</strong><br>
PRID:
<a href="'.$webBaseUrl.'/pr-feedback/pr_feedback.php?pr_id='.urlencode($pr_id).'">
'.$pr_id.'</a>
</p>
</td></tr>

<tr><td><img src="'.$githubImg.'Divider.png" width="100%"></td></tr>';

// ---------------------------
// 9. Questions Loop
// ---------------------------
foreach ($appeal_items as $qid => $appeal) {
    $qText = $questions[$qid] ?? "Question $qid";

    $emailBody .= '
<tr><td style="padding:15px">
<table width="100%" bgcolor="#f1f4f9" style="border-radius:8px" cellpadding="15">
<tr><td><strong>Question:</strong> '.htmlspecialchars($qText).'</td></tr>';

    $emailBody .= '
<tr><td><strong>Builder Appeal Explanation:</strong><br>'
        .htmlspecialchars($appeal['explanation'] ?? 'No explanation provided').'</td></tr>';

    if (!empty($appeal['image_paths'])) {
        $emailBody .= '<tr><td><table width="100%" cellpadding="5"><tr>';
        $count = 0;
        foreach ($appeal['image_paths'] as $img) {
            $imgUrl = $azureBlobBase . rawurlencode($img);
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


$emailBody .= '
<tr>
    <td>
    <table width="100%" cellspacing="0" cellpadding="0">
        <tr>
            <td width="35%">&nbsp</td>
            <td width="30%" align="center" style="padding:12px 25px;background-color:#192f75; text-decoration:none;">
                <a href="'.$webBaseUrl.'/pr-feedback/pr_feedback.php?pr_id='.urlencode($pr_id).'"color:#fff; text-decoration:none;font-weight:bold;font-size:12pt">
                    View Feedback
                </a>
            </td>
            <td width="35%">&nbsp</td>
        </tr>
    </table>
    </td>
</tr>

<tr><td><img src="'.$githubImg.'Footer.png" width="100%"></td></tr>

</table></td></tr></table>
</body></html>';

// ---------------------------
// 10. Trigger Power Automate
// ---------------------------
$flowUrl = 'https://default10f787270c1845afb9ee97e94fd5bc.d8.environment.api.powerplatform.com/powerautomate/automations/direct/workflows/62469676a4f44d61b22674cd7e33b2e0/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=_pysnZPyPOIj5zbQq0chYqOrtLewwi-UCND9aAJvNEE';
$data = [
    "ToEmail"     => trim($feedback['peer_reviewer_email']),
    "SubjectText" => "Appeal Submitted for PRID $pr_id",
    "BodyText"    => $emailBody
];

$ch = curl_init($flowUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($data),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($ch);
if(curl_errno($ch)) {
    echo 'Curl error: ' . curl_error($ch);
} else {
    echo 'Flow response: ' . $response;
}
curl_close($ch);


echo "Appeal email sent.";
