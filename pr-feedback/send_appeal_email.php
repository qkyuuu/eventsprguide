<?php
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ------------------- DB Connection -------------------
$host = "sql103.infinityfree.com";
$username = "if0_40271114";
$password = "QdO20m5hR4JbOHe";
$dbname = "if0_40271114_peer_review_db";

$pr_id = $_GET['pr_id'] ?? null;
if (!$pr_id) die("PR ID missing.");

$mysqli = new mysqli($host, $username, $password, $dbname);
if ($mysqli->connect_error) die("Database connection error");

// ------------------- Get PR Submission -------------------
$stmt = $mysqli->prepare("SELECT * FROM pr_submissions WHERE pr_id = ?");
$stmt->bind_param("s", $pr_id);
$stmt->execute();
$result = $stmt->get_result();
$feedback = $result->fetch_assoc();
$stmt->close();

if (!$feedback) die("Invalid PRID.");

// ------------------- Decode JSON Answers & Images -------------------
$answers = !is_null($feedback['answers']) ? json_decode($feedback['answers'], true) : [];
$images  = !is_null($feedback['image_paths']) ? json_decode($feedback['image_paths'], true) : [];

// ------------------- Fetch Questions -------------------
$questions_sql = $mysqli->query("SELECT * FROM questions");
$questions = [];
while ($row = $questions_sql->fetch_assoc()) {
    $questions[$row['question_id']] = $row['question_text'];
}

// ------------------- Fetch Latest Appeal & Items -------------------
$appeal_items = [];
$stmt = $mysqli->prepare("SELECT appeal_id FROM pr_appeals WHERE pr_id = ? ORDER BY appeal_id DESC LIMIT 1");
$stmt->bind_param("s", $pr_id);
$stmt->execute();
$result = $stmt->get_result();
$appeal_row = $result->fetch_assoc();
$appeal_id = $appeal_row['appeal_id'] ?? null;
$stmt->close();

if ($appeal_id) {
    $stmt = $mysqli->prepare("SELECT * FROM pr_appeal_items WHERE appeal_id = ?");
    $stmt->bind_param("i", $appeal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['image_paths'] = json_decode($row['image_paths'], true); // decode images
        $appeal_items[$row['question_id']] = $row;
    }
    $stmt->close();
}

$mysqli->close();

// ------------------- Fix Builder/Peer Names -------------------
$feedback['builder_name']       = ucwords(strtolower($feedback['builder_name'] ?? ''));
$feedback['peer_reviewer_name'] = ucwords(strtolower($feedback['peer_reviewer_name'] ?? ''));

// ------------------- Build TaskNameShort -------------------
$originalTaskName = $feedback['task_name'] ?? '';
$matches = [];
$shortened = $originalTaskName;

if (preg_match('/^([A-Z]+_[A-Z0-9]+)_.*(_ST\\d+)_?$/u', $originalTaskName, $matches)) {
    $shortened = $matches[1] . $matches[2];
} else {
    $parts = preg_split('/\\s+/', $originalTaskName);
    if (count($parts) >= 2) {
        $shortened = $parts[0] . (preg_match('/_ST\\d+/', end($parts)) ? end($parts) : '');
    }
}

$taskNameShort = htmlspecialchars($shortened, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ------------------- Build Email Body -------------------
$emailBody = '<html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif; margin:0; padding:0;">';
$emailBody .= '
<table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" bgcolor="#e3e3e3">
  <tr>
    <td align="center">
      <table width="1000" border="0" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF" align="center">
        <tr><td bgcolor="#e2e2e2">&nbsp;</td></tr>

        <tr>
        	<td style="padding: 15px; font-size:10.5px" align="right">
            	<a href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) . 
                '" target="_blank" style="color:#000000; text-decoration:none">
                <em>If this email displays incorrectly, click here</em></a>
            </td>
        </tr>

        <tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Header.jpg" width="100%" /></td></tr>
        <tr><td>&nbsp;</td></tr>

        <tr>
          <td style="color:#071952; font-size:25pt; padding:5px 20px; font-weight:700" align="center">
            Feedback Appeal Received
          </td>
        </tr>

        <tr>
          <td style="padding:30px 40px 10px 40px; color:#000000; font-size:12pt">
            <p>Dear '. htmlspecialchars($feedback['peer_reviewer_name']) . ',</p>
            <p><strong>' . htmlspecialchars($feedback['builder_name']) . '</strong> has appealed your feedback. Please review the details below.</p>

            <p style="font-size:14pt;color:#192f75">
                <strong>' . $taskNameShort . '</strong><br/>
                <span style="font-size:12pt">PRID: 
                    <a href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) . '" 
                       target="_blank" style="color:#192f75">' . htmlspecialchars($pr_id) . '</a>
                </span>
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:10px 40px 20px 40px; font-size:12pt">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Divider.png" width="100%" /></td></tr>
';

// ------------------- Loop All Applicable Questions -------------------
foreach ($questions as $qid => $qText) {
    $answerKey = 'q' . $qid;

    if (!isset($answers[$answerKey]) || strtolower($answers[$answerKey]) !== 'applicable') continue;

    $appeal = $appeal_items[$qid] ?? null;

    $emailBody .= '
    <tr>
      <td style="padding-bottom:15px;">
        <table width="100%" style="background-color:#f1f4f9; border-radius:8px;">
          <tr>
            <td style="padding:20px;">
              <table width="100%">
                <tr>
                    <td width="10%"><strong>Question:</strong></td>
                    <td>' . htmlspecialchars($qText) . '</td>
                </tr>
              </table>';

    if ($appeal) {
        $emailBody .= '
        <p style="margin-top:10px;"><strong>Appeal:</strong></p>
        <p>' . nl2br(htmlspecialchars($appeal['explanation'] ?? 'No explanation provided')) . '</p>';

        if (!empty($appeal['image_paths'])) {
            $emailBody .= '<p><strong>Appeal Images:</strong><br>';
            foreach ($appeal['image_paths'] as $img) {
                $url = "https://eventsprguide.infinityfree.me/uploads/" . urlencode($img);
                $emailBody .= '<img src="' . htmlspecialchars($url) . '" alt="Appeal Image" style="max-width:150px; margin-right:5px; margin-top:5px;">';
            }
            $emailBody .= '</p>';
        }
    }

    $emailBody .= '
            </td>
          </tr>
        </table>
      </td>
    </tr>';
}

$emailBody .= '
            </table>
          </td>
        </tr>
        <tr>
        	<td align="center">
            	<table width="100%" border="0" cellspacing="0" cellpadding="0">
                	<tr>
  <td align="center" style="padding:20px 0;">
    <!--[if mso]>
    <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=<?php echo urlencode($pr_id); ?>" style="height:40px;v-text-anchor:middle;width:200px;" arcsize="10%" stroke="f" fillcolor="#192f75">
      <w:anchorlock/>
      <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14pt;font-weight:bold;">View Feedback</center>
    </v:roundrect>
    <![endif]-->
    <![if !mso]>
    <a href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=<?php echo urlencode($pr_id); ?>" 
       style="display:inline-block; background-color:#192f75; color:#ffffff; font-family:Arial,sans-serif; font-size:14pt; font-weight:bold; line-height:40px; text-align:center; text-decoration:none; width:200px; border-radius:4px;">
       View Feedback
    </a>
    <![endif]>
  </td>
</tr>

				</table>
            </td>
        </tr>

        <tr><td align="center"><img src="https://eventsprguide.infinityfree.me/img/Footer.png" width="100%" /></td></tr>
      </table>
    </td>
  </tr>
</table>
</body></html>
';

// ------------------- SEND EMAIL -------------------
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp-relay.brevo.com';
    $mail->SMTPAuth = true;
    $mail->Username = '9aa2c9001@smtp-brevo.com';
    $mail->Password = 'xsmtpsib-2cb4cb8c25ef265ddd14f13d558ed472e60a0194c17b82882e5ac8b0ef6699a5-dvJBO7UuPdH70xe0';
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    $mail->setFrom('m.pastoral19@gmail.com', 'PR System');
    $mail->addAddress($feedback['peer_reviewer_email'], $feedback['peer_reviewer_name']);

    $mail->isHTML(true);
    $mail->Subject = "Appeal Submitted for PRID $pr_id";
    $mail->Body = $emailBody;

    $mail->send();
    echo "Email Sent.";

} catch (Exception $e) {
    echo "Mailer Error: " . $mail->ErrorInfo;
}
?>
