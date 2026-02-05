<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

// ---------------------------
// 1. Azure SQL Connection
// ---------------------------
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid"      => "qmsadmin",
    "PWD"      => "Codegenqms!",
    "Encrypt"  => true,
    "LoginTimeout" => 60
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// ---------------------------
// 2. Get PR ID
// ---------------------------
$pr_id = $_GET['pr_id'] ?? null;
$message = "Invalid request. PRID missing.";

if ($pr_id) {

    // Fetch builder + peer reviewer info
    $sql  = "SELECT builder_name, peer_reviewer_name, peer_reviewer_email, task_name FROM pr_submissions WHERE pr_id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$pr_id]);
    $feedback = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if (!$feedback) {
        $message = "PRID $pr_id not found.";
    } else {
        $builder_name = ucwords($feedback['builder_name']);
        $peer_name    = ucwords($feedback['peer_reviewer_name']);
        $peer_email   = $feedback['peer_reviewer_email'];
        $task_name    = $feedback['task_name'];

        // ---------------------------
        // 3. Update status
        // ---------------------------
        $update_sql = "UPDATE pr_submissions SET status = ? WHERE pr_id = ?";
        $newStatus  = "Completed - Valid";
        sqlsrv_query($conn, $update_sql, [$newStatus, $pr_id]);

        $message = "Thank you {$builder_name} for accepting the review!";

        // ---------------------------
        // 4. Send Email to Peer Reviewer
        // ---------------------------
        if (!empty($peer_email)) {
            $emailBody = '<html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif; margin:0; padding:0;">';
            $emailBody .= '
            <table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" bgcolor="#e3e3e3">
              <tr><td align="center">
                <table width="800" border="0" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF" align="center">
                  <tr><td bgcolor="#e2e2e2">&nbsp;</td></tr>
                  <tr>
                    <td style="padding: 15px; font-size:10.5px" align="right">
                        <a href="https://eventsprguide.azurewebsites.net/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) . '" target="_blank" style="color:#000; text-decoration:none;">
                        <em>View in browser</em></a>
                    </td>
                  </tr>
                  <tr>
                    <td align="center"><img src="https://raw.githubusercontent.com/qkyuuu/eventsprguide/main/img/Header.jpg" width="100%" alt="Header"/></td>
                  </tr>
                  <tr><td>&nbsp;</td></tr>
                  <tr>
                    <td style="color:#071952; font-size:25pt; padding:5px 20px; font-weight:700" align="center">Review Accepted</td>
                  </tr>
                  <tr>
                    <td style="padding:20px; font-size:12pt; color:#000;">
                      <p>Dear <strong>' . htmlspecialchars($peer_name) . '</strong>,</p>
                      <p>The builder, <strong>' . htmlspecialchars($builder_name) . '</strong>, has accepted your peer review for the task:</p>
                      <p style="font-size:14pt;"><strong>' . htmlspecialchars($task_name) . '</strong></p>
                      <p>PRID: <a href="https://eventsprguide.azurewebsites.net/pr-feedback/pr_feedback.php?pr_id=' . urlencode($pr_id) . '" target="_blank">' . htmlspecialchars($pr_id) . '</a></p>
                      <p>Thank you for your contribution to the peer review process!</p>
                      <hr>
                      <p style="font-size:10pt;color:#666;">This is an automated message from the Peer Review System.</p>
                    </td>
                  </tr>
                  <tr><td>&nbsp;</td></tr>
                  <tr><td align="center"><img src="https://raw.githubusercontent.com/qkyuuu/eventsprguide/main/img/Footer.png" width="100%" alt="Footer"/></td></tr>
                </table>
              </td></tr>
            </table>
            </body></html>';

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp-relay.brevo.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = '9aa2c9001@smtp-brevo.com';
                $mail->Password   = 'xsmtpsib-...'; // truncated for security
                $mail->SMTPSecure = 'tls';
                $mail->Port       = 587;

                $mail->setFrom('m.pastoral19@gmail.com', 'Peer Review System');
                $mail->addAddress($peer_email, $peer_name);

                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Encoding = 'base64';
                $mail->Subject = mb_encode_mimeheader("Review Accepted: $task_name (PRID: $pr_id)", 'UTF-8', 'B');
                $mail->Body = $emailBody;

                $mail->send();
            } catch (Exception $e) {
                error_log("Email sending failed: {$mail->ErrorInfo}");
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review Accepted</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f7fc; margin:0; padding:0; }
    .container { max-width:500px; margin:50px auto; background:#fff; padding:40px; border-radius:8px; text-align:center; box-shadow:0 4px 12px rgba(0,0,0,0.1);}
    h2 { color:#4CAF50; font-size:1.8rem; margin-bottom:20px; }
    p { font-size:1.1rem; color:#555; margin-bottom:20px; }
    a { text-decoration:none; color:#fff; background:#007BFF; padding:12px 20px; border-radius:5px; font-weight:bold;}
    a:hover { background:#0056b3; }
    .footer { font-size:0.9rem; color:#999; margin-top:30px; }
</style>
</head>
<body>
<div class="container">
    <h2><?= htmlspecialchars($message) ?></h2>
    <?php if ($pr_id): ?>
        <p><a href="https://eventsprguide.azurewebsites.net/pr-feedback/pr_feedback.php?pr_id=<?= urlencode($pr_id) ?>">View your feedback</a></p>
    <?php endif; ?>
    <div class="footer">&copy; 2025 Peer Review Platform. All rights reserved.</div>
</div>
</body>
</html>
