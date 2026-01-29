<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

// DB Connection same as your other scripts
$host = "sql103.infinityfree.com";
$username = "if0_40271114";
$password = "QdO20m5hR4JbOHe";
$dbname = "if0_40271114_peer_review_db";

$mysqli = new mysqli($host, $username, $password, $dbname);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

$pr_id = $_GET['pr_id'] ?? null;

if ($pr_id) {
    // Fetch builder + peer reviewer info
    $stmt = $mysqli->prepare("SELECT builder_name, peer_reviewer_name, peer_reviewer_email, task_name FROM pr_submissions WHERE pr_id = ?");
    $stmt->bind_param("s", $pr_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $builder_name = '';
    $peer_name = '';
    $peer_email = '';
    $task_name = '';
    if ($row = $result->fetch_assoc()) {
        $builder_name = ucwords($row['builder_name']);
        $peer_name = ucwords($row['peer_reviewer_name']);
        $peer_email = $row['peer_reviewer_email'];
        $task_name = $row['task_name'];
    }
    $stmt->close();

    // Build Email Body
    $emailBody = '<html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif; margin:0; padding:0;">';
$emailBody .= '
<table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" bgcolor="#e3e3e3">
  <tr>
    <td align="center">
      <table width="800" border="0" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF" align="center">
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
          <td style="color:#071952; font-size:25pt; padding:5px 20px; font-weight:700" align="center">Review Accepted</td>
        </tr>
        
        <tr>
          <td style="padding:20px; font-size:12pt; color:#000;">
';

$emailBody .= "
  <p>Dear <strong>{$peer_name}</strong>,</p>
  <p>The builder, <strong>{$builder_name}</strong> has accepted your peer review for the task:</p>
  <p style='font-size:14pt;'><strong>{$task_name}</strong></p>
  <p>PRID: <a href='https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id={$pr_id}' target='_blank'>{$pr_id}</a></p>
  <p>Thank you for your contribution to the peer review process!</p>
  <hr>
  <p style='font-size:10pt;color:#666;'>This is an automated message from the Peer Review System.</p>
  </td>
</tr>
<tr><td>&nbsp;</td></tr><tr><td align='center'><img src='https://eventsprguide.infinityfree.me/img/Footer.png' width='100%' alt='Footer'/></td></tr>
</table> <!-- closes inner 800px table -->
</td>
</tr>
</table> <!-- closes outermost table -->
</body></html>";


    // 🔍 PREVIEW MODE: Show email in browser, don't send
    if (isset($_GET['preview'])) {
        header('Content-Type: text/html; charset=UTF-8');
        echo $emailBody;
        exit;
    }

    // Update status
    $stmt = $mysqli->prepare("UPDATE pr_submissions SET status = ? WHERE pr_id = ?");
    $newStatus = "Completed - Valid";
    $stmt->bind_param("ss", $newStatus, $pr_id);

    if ($stmt->execute()) {
        $message = "Thank you " . htmlspecialchars($builder_name) . " for your response!";

        // ✅ Send notification email to the Peer Reviewer
        if (!empty($peer_email)) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp-relay.brevo.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = '9aa2c9001@smtp-brevo.com';
                $mail->Password   = 'xsmtpsib-2cb4cb8c25ef265ddd14f13d558ed472e60a0194c17b82882e5ac8b0ef6699a5-dvJBO7UuPdH70xe0';
                $mail->SMTPSecure = 'tls';
                $mail->Port       = 587;

                $mail->setFrom('m.pastoral19@gmail.com', 'Peer Review System');
                $mail->addAddress($peer_email, $peer_name);

                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Encoding = 'base64';

                $subject = "Review Accepted: $task_name (PRID: $pr_id)";
                $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
                $mail->Body = $emailBody;

                // Log SMTP communication
                $mail->SMTPDebug = 0;
                $mail->Debugoutput = function($str, $level) {
                    file_put_contents(__DIR__ . '/email_debug.log', "[".date('Y-m-d H:i:s')."] $str\n", FILE_APPEND);
                };

                $mail->send();

                file_put_contents(__DIR__ . '/email_debug.log', "[".date('Y-m-d H:i:s')."] Notification sent to $peer_email\n", FILE_APPEND);
            } catch (Exception $e) {
                file_put_contents(__DIR__ . '/email_debug.log', "[".date('Y-m-d H:i:s')."] Notification failed for $peer_email: {$mail->ErrorInfo}\n", FILE_APPEND);
            }
        }

    } else {
        $message = "Failed to update the status. Please contact support.";
    }
    $stmt->close();
} else {
    $message = "Invalid request. PRID missing.";
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Review Accepted</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Arial', sans-serif;
            background: #f4f7fc;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            padding: 20px;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 40px;
            text-align: center;
            width: 100%;
            max-width: 500px;
        }
        h2 { color: #4CAF50; font-size: 1.8rem; margin-bottom: 20px; }
        p { font-size: 1.1rem; margin-bottom: 20px; color: #555; }
        a {
            text-decoration: none;
            color: #fff;
            background-color: #007BFF;
            padding: 12px 20px;
            border-radius: 5px;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        a:hover { background-color: #0056b3; }
        .footer {
            font-size: 0.9rem;
            color: #999;
            margin-top: 30px;
        }
    </style>
</head>
<body>
<div class="container">
    <h2><?= htmlspecialchars($message) ?></h2><br/>
    <?php if ($pr_id): ?>
        <p><a href="https://eventsprguide.infinityfree.me/pr-feedback/pr_feedback.php?pr_id=<?= urlencode($pr_id) ?>">View your feedback</a></p>
    <?php endif; ?>
    <div class="footer">
        <p>&copy; 2025 Peer Review Platform. All rights reserved.</p>
    </div>
</div>
</body>
</html>
