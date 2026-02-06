<?php
    
 ob_start(); // Start output buffering   
error_reporting(E_ALL);
ini_set('display_errors', 1);

// InfinityFree database connection
$host = "sql103.infinityfree.com";
$username = "if0_40271114";
$password = "QdO20m5hR4JbOHe";
$dbname = "if0_40271114_peer_review_db";

$mysqli = new mysqli($host, $username, $password, $dbname);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

//
// 1. GET POST DATA
//
$pr_id = $_POST['pr_id'] ?? null;
$builder_answers = $_POST['builder_answer'] ?? [];
$explanations = $_POST['explanation'] ?? [];
$builder_email = ""; // We fetch it from pr_submissions

//
// 2. VALIDATE PR_ID
//
if (!$pr_id) {
    die("Invalid PR ID.");
}

//
// 3. GET BUILDER EMAIL FROM pr_submissions
//
$stmt = $mysqli->prepare("SELECT builder_email FROM pr_submissions WHERE pr_id = ?");
$stmt->bind_param("s", $pr_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    die("PR Submission not found.");
}

$builder_email = $row['builder_email'];

//
// 4. INSERT INTO pr_appeals (one row per appeal)
//
$stmt = $mysqli->prepare("
    INSERT INTO pr_appeals (pr_id, builder_email)
    VALUES (?, ?)
");
$stmt->bind_param("ss", $pr_id, $builder_email);
$stmt->execute();
$appeal_id = $stmt->insert_id;  // <-- IMPORTANT
$stmt->close();

//
// 5. LOOP THROUGH EACH QUESTION ANSWER AND SAVE INTO pr_appeal_items
//
foreach ($builder_answers as $qid => $answer) {

    $explanation = $explanations[$qid] ?? "";

    //
    // 5a. HANDLE IMAGE UPLOADS
    //
    $uploadedFiles = [];

    if (isset($_FILES['builder_images']['name'][$qid]) &&
        !empty($_FILES['builder_images']['name'][$qid][0])) {

        foreach ($_FILES['builder_images']['name'][$qid] as $idx => $origName) {

            $tmpName = $_FILES['builder_images']['tmp_name'][$qid][$idx];
            $ext = pathinfo($origName, PATHINFO_EXTENSION);

            // Safe unique filename
            $newName = "appeal_{$pr_id}_Q{$qid}_" . time() . "_{$idx}." . $ext;

            // Move to uploads
            if (move_uploaded_file($tmpName, "../uploads/" . $newName)) {
                $uploadedFiles[] = $newName;
            }
        }
    }

    $images_json = json_encode($uploadedFiles);

    //
    // 5b. INSERT INTO pr_appeal_items
    //
    $stmt = $mysqli->prepare("
        INSERT INTO pr_appeal_items 
        (appeal_id, question_id, builder_answer, explanation, image_paths)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("iisss",
        $appeal_id,
        $qid,
        $answer,
        $explanation,
        $images_json
    );

    $stmt->execute();
    $stmt->close();
}

//
// 6. FINISH
//
$mysqli->close();

// Optionally send email
$sendEmailUrl = "https://eventsprguide.infinityfree.me/pr-feedback/send_appeal_email.php?pr_id=" . urlencode($pr_id);
@file_get_contents($sendEmailUrl);

// Force redirect to feedback page
header("Location: pr_feedback.php?pr_id=" . urlencode($pr_id));
exit;

?>
