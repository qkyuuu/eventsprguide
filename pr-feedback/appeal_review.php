<?php
// Enable error reporting (for debugging; disable in production)
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

// Get PRID from URL
$pr_id = $_GET['pr_id'] ?? null;

// Fetch data
if ($pr_id) {
    $stmt = $mysqli->prepare("SELECT * FROM pr_submissions WHERE pr_id = ?");
    $stmt->bind_param("s", $pr_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $feedback = $result->fetch_assoc();
    $stmt->close();

    $answers = !empty($feedback['answers']) ? json_decode($feedback['answers'], true) : [];
    $questions_result = $mysqli->query("SELECT * FROM questions");
} else {
    echo "Invalid PRID.";
    exit;
}

$mysqli->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appeal Review</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="pr_feedback.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container">

<?php if ($pr_id): ?>

<?php if ($feedback): ?>
<div class="feedback-card">
    <div class="taskInfo">
        <h3><strong><?= htmlspecialchars($feedback['task_name']) ?></strong></h3>
        <p><strong><?= htmlspecialchars($feedback['pr_id']) ?></strong></p>
        <p><strong>Peer Reviewer:</strong> <?= htmlspecialchars(ucwords($feedback['peer_reviewer_name'])) ?> (<?= htmlspecialchars($feedback['peer_reviewer_email']) ?>)</p>
        <p><strong>Builder:</strong> <?= htmlspecialchars(ucwords($feedback['builder_name'])) ?> (<?= htmlspecialchars($feedback['builder_email']) ?>)</p>
        <p><strong>Date:</strong> <?= htmlspecialchars($feedback['submission_date']) ?></p>
    </div>

    <h4>Feedback</h4>
    <form id="appealForm" action="submit_appeal.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="pr_id" value="<?= htmlspecialchars($pr_id) ?>">

        <ul>
        <?php 
        while ($question = $questions_result->fetch_assoc()) {
    $qid = $question['question_id'];
    $answer = $answers['q'.$qid] ?? null;

    if ($answer && strtolower($answer) !== 'not applicable') {

        echo "<li><p><strong>" . htmlspecialchars($question['question_text']) . "</strong></p>";

        echo "<strong>Peer Reviewer Answer:</strong> " . htmlspecialchars($answer);

        // FATALITY + REMARKS
        if (strtolower($answer) === "applicable") {
            $fatality = $answers['fatality'.$qid] ?? null;
            $fatality_display = $fatality === 'fatal' ? "<span class='highlight'>Fatal Error</span>" :
                              ($fatality === 'nonFatal' ? "Non-Fatal Error" : "Not specified");
            echo "<br><strong>Fatality:</strong> $fatality_display";

            $remarks = $answers['remarks'.$qid] ?? 'No remarks provided';
            echo "<br><strong>Remarks:</strong> " . htmlspecialchars($remarks);
        }

        // PROOF IMAGES
        $images = isset($feedback['image_paths']) ? json_decode($feedback['image_paths'], true) : [];
        if (!empty($images['q'.$qid])) {
            echo "<br><strong>Proof:</strong><br>";
            foreach ($images['q'.$qid] as $img) {
                $path = '../uploads/' . htmlspecialchars($img);
                echo "<img src='$path' class='img-thumbnail preview-image' style='max-width:150px;margin:5px;cursor:pointer;' data-bs-toggle='modal' data-bs-target='#imageModal' data-img-src='$path'>";
            }
        } else {
            echo "<p>No images uploaded.</p>";
        }
        // RADIO BUTTON ANSWER
        echo "<hr><p style='margin-bottom:0px'><strong>Your Answer:</strong></p>";

        $applicableChecked = strtolower($answer) === "applicable" ? "checked" : "";
        $notApplicableChecked = strtolower($answer) === "not applicable" ? "checked" : "";

        echo "
        <div class='na-options'>
            <div class='form-check'>
                <label class='form-check-label'>
                <input class='form-check-input answer-radio' 
                       type='radio'
                       name='builder_answer[$qid]'
                       value='Applicable'
                       data-qid='$qid'
                       $applicableChecked> Applicable</label>
            </div>

            <div class='form-check'>
            	<label class='form-check-label'>
                <input class='form-check-input answer-radio'
                       type='radio'
                       name='builder_answer[$qid]'
                       value='Not Applicable'
                       data-qid='$qid'
                       $notApplicableChecked> Not Applicable</label>
            </div>
       </div>
        ";


        // BUILDER EXPLANATION + IMAGE UPLOAD (initially hidden if Applicable is selected)
        $hideBlock = strtolower($answer) === "applicable" ? "style='display:none;'" : "";

        echo "
            <div id='appealBlock_$qid' class='mt-2' $hideBlock>
                <strong>Builder's Explanation:</strong><br>
                <textarea name='explanation[$qid]' class='form-control' rows='3' placeholder='Explain your appeal...'></textarea>

                <br><strong>Upload Supporting Images (Optional):</strong><br>
                <input type='file' name='builder_images[$qid][]' multiple accept='image/*' class='form-control'>
            </div>
        ";

        echo "</li>";
    }
}
        ?>
        </ul>

        <div class="mb-3">
            <button type="submit" class="btn btn-warning" id="submitAppeal"><i class="bi bi-send"></i> Submit Appeal</button>
        </div>
    </form>
</div>
<?php else: ?>
<div class="alert alert-warning">No feedback found for PRID <?= htmlspecialchars($pr_id) ?>.</div>
<?php endif; ?>

<?php else: ?>
<div class="alert alert-danger">Invalid PRID.</div>
<?php endif; ?>

</div>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-dark">
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid rounded" alt="Preview Image">
      </div>
    </div>
  </div>
</div>
<!-- Loading spinner -->
<div id="loadingOverlay" 
     style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(255,255,255,0.85); z-index:9999; text-align:center;">
    <div style="position:relative; top:40%;">
        <div class="spinner-border text-primary" style="width:4rem;height:4rem;"></div>
        <p style="margin-top:15px; font-size:18px;">Submitting Appeal...</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.preview-image').forEach(img => {
  img.addEventListener('click', () => {
    document.getElementById('modalImage').src = img.dataset.imgSrc;
  });
});
document.querySelectorAll('.answer-radio').forEach(radio => {
    radio.addEventListener('change', () => {
        let qid = radio.dataset.qid;
        let block = document.getElementById('appealBlock_' + qid);

        if (radio.value === "Applicable") {
            block.style.display = "none";
        } else {
            block.style.display = "block";
        }
    });
});

document.getElementById("appealForm").addEventListener("submit", function(e) {
    document.getElementById("loadingOverlay").style.display = "block";
});
</script>

</body>
</html>
