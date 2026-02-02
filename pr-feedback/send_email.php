<?php
// ------------------- Get PR ID -------------------
$pr_id = $_POST['pr_id'] ?? $_GET['pr_id'] ?? null;
if (!$pr_id) die("PRID is required.");

// ------------------- Database Connection -------------------
$host = "sql103.infinityfree.com";
$username = "if0_40271114";
$password = "QdO20m5hR4JbOHe";
$dbname = "if0_40271114_peer_review_db";

$mysqli = new mysqli($host, $username, $password, $dbname);
if ($mysqli->connect_error) die("Connection failed: " . $mysqli->connect_error);
$mysqli->set_charset('utf8mb4');

// ------------------- Fetch Feedback -------------------
$stmt = $mysqli->prepare("SELECT * FROM pr_submissions WHERE pr_id = ?");
$stmt->bind_param("s", $pr_id);
$stmt->execute();
$result = $stmt->get_result();
$feedback = $result->fetch_assoc();
$stmt->close();

// ------------------- Decode Answers & Images -------------------
$answers = !is_null($feedback['answers']) ? json_decode($feedback['answers'], true) : [];
$images  = !is_null($feedback['image_paths']) ? json_decode($feedback['image_paths'], true) : [];

// ------------------- Fetch Questions -------------------
$questions_result = $mysqli->query("SELECT * FROM questions");
$questions = [];
while ($row = $questions_result->fetch_assoc()) {
    $questions[$row['question_id']] = $row['question_text'];
}
$mysqli->close();

// ------------------- Capitalize Names -------------------
$feedback['builder_name'] = ucwords(strtolower($feedback['builder_name'] ?? ''));
$feedback['peer_reviewer_name'] = ucwords(strtolower($feedback['peer_reviewer_name'] ?? ''));

// ------------------- Prepare Task Name -------------------
$originalTaskName = $feedback['task_name'] ?? '';
$matches = [];
$shortened = $originalTaskName;

if (preg_match('/^([A-Z]+_[A-Z0-9]+)_.*(_ST\d+)_?$/u', $originalTaskName, $matches)) {
    $shortened = $matches[1] . $matches[2];
} else {
    $parts = preg_split('/\s+/', $originalTaskName);
    if (count($parts) >= 2) {
        $shortened = $parts[0] . (preg_match('/_ST\d+/', end($parts)) ? end($parts) : '');
    }
}
$taskNameShort = htmlspecialchars($shortened, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ------------------- Build Email Body (HTML) -------------------
$emailBody = '<html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif; margin:0; padding:0;">';
$emailBody .= '<table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" bgcolor="#e3e3e3"><tr><td align="center"><table width="1000" border="0" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF" align="center"><tr><td bgcolor="#e2e2e2">&nbsp;</td></tr><tr><td style="padding: 15px; font-size:10.5px" align="right"><a href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) . '" target="_blank" style="color:#000000; text-decoration:none"><em>View in browser</em></a></td></tr><tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Header.jpg" width="100%" alt="Header" /></td></tr><tr><td>&nbsp;</td></tr><tr><td style="color:#071952; font-size:25pt; padding:5px 20px; font-weight:700" align="center">Feedback Received</td></tr><tr><td style="padding:30px 40px 10px 40px; color:#000000; font-size:12pt"><p>Dear '. htmlspecialchars($feedback['builder_name']) . ',</p><p>Your task has been reviewed by <strong>' . htmlspecialchars($feedback['peer_reviewer_name']) . '</strong>.</p><p style="font-size:14pt;color:#192f75"><strong>' . $taskNameShort . '</strong><br/><span style="font-size:12pt">PRID: ' . htmlspecialchars($pr_id) . '</span></p></td></tr><tr><td style="padding:10px 40px 20px 40px; color:#000000; font-size:12pt"><table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse;"><tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Divider.png" width="100%" alt="Divider" /></td></tr>';

foreach ($questions as $qid => $qText) {
    $answerKey = 'q' . $qid;
    if (isset($answers[$answerKey]) && strtolower($answers[$answerKey]) === 'applicable') {
        $emailBody .= '<tr><td style="padding-bottom:15px;"><table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse; background-color:#f1f4f9; border-radius:8px;"><tr><td style="padding:20px; font-family:Arial, sans-serif; font-size:12pt; color:#000000;"><table width="100%" border="0" cellspacing="0" cellpadding="0"><tr><td width="15%"><strong>Question:</strong></td><td>' . htmlspecialchars($qText) . '</td></tr><tr><td><strong>Answer:</strong></td><td>'. htmlspecialchars($answers[$answerKey]) . '</td></tr>';
        
        $fatalityKey = 'fatality' . $qid;
        $fatality = $answers[$fatalityKey] ?? 'Not specified';
        $fat_disp = ($fatality === 'fatal') ? "<span style='color:red;'>Fatal Error</span>" : (($fatality === 'nonFatal') ? "Non-Fatal Error" : "Not specified");
        
        $emailBody .= '<tr><td><strong>Fatality:</strong></td><td>' . $fat_disp . '</td></tr>';
        $remarksKey = 'remarks' . $qid;
        $remarks = $answers[$remarksKey] ?? 'No remarks provided';
        $emailBody .= '<tr><td><strong>Remarks:</strong></td><td>' . htmlspecialchars($remarks) . '</td></tr></table>';

        $qImages = $images[$answerKey] ?? [];
        if (!empty($qImages)) {
            $emailBody .= '<table width="100%" border="0" cellspacing="0" cellpadding="0"><tr>';
            $count = 0;
            foreach ($qImages as $img) {
                $imgUrl = 'https://eventsprguide.infinityfree.me/uploads/' . str_replace(' ', '%20', $img);
                $emailBody .= '<td style="padding:5px; text-align:center; width:33%;"><img src="' . $imgUrl . '" width="100%"></td>';
                $count++;
                if ($count % 3 == 0) $emailBody .= '</tr><tr>';
            }
            $emailBody .= '</tr></table>';
        }
        $emailBody .= '</td></tr></table></td></tr>';
    }
}

$emailBody .= '</table></td></tr><tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Footer.png" width="100%"/></td></tr></table></td></tr></table></body></html>';

// ------------------- POWER AUTOMATE TRIGGER -------------------

// 1. YOUR ACTION: Paste your "HTTP POST URL" from Power Automate here
$flowUrl = 'https://default10f787270c1845afb9ee97e94fd5bc.d8.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/a4121885fc0243a1a3ec9ffe0d57c42b/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=_N3ad7Adnjiw-DNpQzNQda7f80ExDkpeVH4U4IfTPK8';

$recipientEmail = $feedback['builder_email'] ?? 'v-jopastoral@microsoft.com';
$recipientName  = $feedback['builder_name'] ?? 'Builder';
$subject        = "Peer Review Feedback Received: $taskNameShort (PRID: $pr_id)";

// Data bundle to send to Power Automate
$data = [
    "recipient_email" => $recipientEmail,
    "recipient_name"  => $recipientName,
    "subject"         => $subject,
    "email_body"      => $emailBody
];

// cURL - The "Messenger"
$ch = curl_init($flowUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Important for InfinityFree

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ------------------- Final Response -------------------
if ($httpCode >= 200 && $httpCode < 300) {
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "Flow triggered for $recipientEmail"]);
    } else {
        echo "Email sent via Power Automate to $recipientEmail";
    }
} else {
    echo "Error: Could not trigger Power Automate. Code: $httpCode. Response: $response";
}
?>
