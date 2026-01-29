<?php
// ------------------- PHPMailer -------------------
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
// Capitalize the first letter of each word in names
$feedback['builder_name'] = ucwords(strtolower($feedback['builder_name'] ?? ''));
$feedback['peer_reviewer_name'] = ucwords(strtolower($feedback['peer_reviewer_name'] ?? ''));

// ------------------- Prepare Task Name -------------------
// Example task name from database
$originalTaskName = $feedback['task_name'] ?? '';

// Use regex to extract prefix and suffix parts
// Prefix: characters up to and including the first underscore-separated code
// Suffix: _ST followed by digits at the end
$matches = [];
$shortened = $originalTaskName; // fallback if no match

if (preg_match('/^([A-Z]+_[A-Z0-9]+)_.*(_ST\d+)_?$/u', $originalTaskName, $matches)) {
    // $matches[1] is prefix like CHN_SREVM78206
    // $matches[2] is suffix like _ST1707821
    $shortened = $matches[1] . $matches[2];
} else {
    // If doesn't match pattern try to fallback to first and last chunks
    $parts = preg_split('/\s+/', $originalTaskName);
    if (count($parts) >= 2) {
        $shortened = $parts[0] . (preg_match('/_ST\d+/', end($parts)) ? end($parts) : '');
    }
}

// Then encode for HTML escaping as before
$taskNameShort = htmlspecialchars($shortened, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ------------------- Build Email Body -------------------
$emailBody = '<html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif; margin:0; padding:0;">';
$emailBody .= '
<table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" bgcolor="#e3e3e3">
  <tr>
    <td align="center">
      <table width="1000" border="0" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF" align="center">
        <tr>
          <td bgcolor="#e2e2e2">&nbsp;</td>
        </tr>
        <tr>
        	<td style="padding: 15px; font-size:10.5px" align="right">
            	<a href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) . '" target="_blank" style="color:#000000; text-decoration:none"><em>If there are problems with how this message is displayed, click here to view it in a web browser</em></a> 
            </td>
        </tr>
        <tr>
        <tr>
          <td align="center"><img src="https://eventsprguide.infinityfree.me/img/Header.jpg" width="100%" alt="Header" /></td>
        </tr>
        </tr>
        <tr>
          <td>&nbsp;</td>
        </tr>
        <tr>
          <td style="color:#071952; font-size:25pt; padding:5px 20px; font-weight:700" align="center">Feedback Received</td>
        </tr>
        <tr>
          <td style="padding:30px 40px 10px 40px; color:#000000; font-size:12pt">
            <p>Dear '. htmlspecialchars($feedback['builder_name']) . ',</p>
            <p>Your task has been reviewed by <strong>' . htmlspecialchars($feedback['peer_reviewer_name']) . '</strong>. Please see the details below.</p>
            <p style="font-size:14pt;color:#192f75"><strong>' . $taskNameShort . '</strong><br/><span style="font-size:12pt">PRID: <a href=https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) .' target="_blank" style="color:#192f75"> '.htmlspecialchars($pr_id) . '</a></span></p>
          </td>
        </tr>
        <tr>
          <td style="padding:10px 40px 20px 40px; color:#000000; font-size:12pt">
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse;"> 	
        		<tr>
          			<td align="center"><img src="https://eventsprguide.infinityfree.me/img/Divider.png" width="100%" alt="Divider" /></td>
        		</tr>';
foreach ($questions as $qid => $qText) {
    $answerKey = 'q' . $qid;
    if (isset($answers[$answerKey]) && strtolower($answers[$answerKey]) === 'applicable') {

        // Outer wrapper table for spacing between rows
        $emailBody .= '
        <tr>
            <td style="padding-bottom:15px;"> 
                <table width="100%" border="0" cellspacing="0" cellpadding="0" 
                    style="border-collapse: collapse; background-color:#f1f4f9; border-radius:8px;">
                    <tr>
                        <td style="padding:20px; font-family:Arial, sans-serif; font-size:12pt; color:#000000;">
                          <table width="100%" border="0" cellspacing="0" cellpadding="0">
                            <tr>
                              <td width="10%"><strong>Question:</strong></td> <td width="90%">' . htmlspecialchars($qText) . '</td>
                            </tr>
                            <tr>
                              <td width="10%"><strong>Answer:</strong></td> <td width="90%">'. htmlspecialchars($answers[$answerKey]) . '</td>
                            </tr>';

        // Fatality
        $fatalityKey = 'fatality' . $qid;
        $fatality = $answers[$fatalityKey] ?? 'Not specified';
        $fatality_display = ($fatality === 'fatal')
            ? "<span style='color:red;'>Fatal Error</span>"
            : (($fatality === 'nonFatal') ? "Non-Fatal Error" : "Not specified");

        $emailBody .= '<tr>
                        <td width="10%"><strong>Fatality:</strong></td> <td width="90%">' . $fatality_display . '</td>
                      </tr>';

        // Remarks
        $remarksKey = 'remarks' . $qid;
        $remarks = $answers[$remarksKey] ?? 'No remarks provided';
        $emailBody .= '<tr>
                        <td width="10%"><strong>Remarks:</strong></td> <td width="90%">' . htmlspecialchars($remarks) . '</td>
                      </tr>
                      </table>';

        // Images
        $qImages = $images[$answerKey] ?? [];
        if (!empty($qImages)) {
            $emailBody .= '
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" 
                                style="border-collapse: collapse; margin-bottom:10px;"><tr>';
            $count = 0;
            foreach ($qImages as $img) {
                $imgUrl = 'https://eventsprguide.infinityfree.me/uploads/' . str_replace(' ', '%20', $img);
                $emailBody .= '
                                <td style="padding:5px; text-align:center; width:33%;">
                                    <img src="' . $imgUrl . '" width="100%" alt="Proof">
                                </td>';
                $count++;
                if ($count % 3 == 0) $emailBody .= '</tr><tr>';
            }
            $remaining = 3 - ($count % 3);
            if ($remaining < 3) {
                for ($i = 0; $i < $remaining; $i++) $emailBody .= '<td>&nbsp;</td>';
            }
            $emailBody .= '</tr></table>';
        } else {
            $emailBody .= '<p style="color:blue; margin:0;">No images for this question.</p>';
        }

        // Close inner table
        $emailBody .= '
                        </td>
                    </tr>
                </table>
            </td>
        </tr>';
    }
}

// Close main feedback table
$emailBody .= '</table>';

$acceptUrl = 'https://eventsprguide.infinityfree.me/pr-feedback/accept_review.php?pr_id=' . urlencode($pr_id);
$appealUrl = 'https://eventsprguide.infinityfree.me/pr-feedback/appeal_review.php?pr_id=' . urlencode($pr_id);
$emailBody .= '
<tr><td style="padding:10px 20px; font-size:12pt">How would you like to proceed with this peer review?</td></tr>
<tr>
<td>
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
</td>
</tr>
<tr><td>&nbsp;</td></tr><tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Footer.png" width="100%" alt="Footer"/></td></tr>
</table>
</td></tr></table>
</body></html>';

// Preview mode: show email in browser, don’t send
if (isset($_GET['preview'])) {
    header('Content-Type: text/html; charset=UTF-8');
    echo $emailBody;
    exit;
}

// ------------------- Send Email via PHPMailer -------------------
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = '9aa2c9001@smtp-brevo.com';
    $mail->Password   = 'xsmtpsib-2cb4cb8c25ef265ddd14f13d558ed472e60a0194c17b82882e5ac8b0ef6699a5-dvJBO7UuPdH70xe0';
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    // Enable SMTP debug and log to a file
    $mail->SMTPDebug = 2; // 0=off, 1=client, 2=client+server
    $mail->Debugoutput = function($str, $level) {
        file_put_contents(__DIR__ . '/email_debug.log', "[".date('Y-m-d H:i:s')."] $str\n", FILE_APPEND);
    };

    $mail->setFrom('m.pastoral19@gmail.com', 'PR Email System');

    $recipientEmail = $feedback['builder_email'] ?? 'v-jopastoral@microsoft.com';
    $recipientName  = $feedback['builder_name'] ?? 'Builder';
    $mail->addAddress($recipientEmail, $recipientName);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    $fullSubject = "Peer Review Feedback Received: $taskNameShort (PRID: $pr_id)";
    $mail->Subject = mb_encode_mimeheader($fullSubject, 'UTF-8', 'B');
    $mail->Body = $emailBody;

    // Attempt to send
    $mail->send();

    // Log success
    file_put_contents(__DIR__ . '/email_debug.log', "[".date('Y-m-d H:i:s')."] Email sent successfully to $recipientEmail\n", FILE_APPEND);

    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Email sent successfully to ' . $recipientEmail]);
    } else {
        echo 'Email sent successfully to ' . $recipientEmail;
    }

} catch (Exception $e) {
    // Display the PHPMailer error and exception directly
    echo "<h2>Email Sending Failed</h2>";
    echo "<p><strong>PHPMailer Error:</strong> {$mail->ErrorInfo}</p>";
    echo "<p><strong>Exception Message:</strong> {$e->getMessage()}</p>";
    
    // Optional: still log to file for reference
    file_put_contents(__DIR__ . '/email_debug.log', "[".date('Y-m-d H:i:s')."] PHPMailer Error: {$mail->ErrorInfo}, Exception: {$e->getMessage()}\n", FILE_APPEND);
}


?>


