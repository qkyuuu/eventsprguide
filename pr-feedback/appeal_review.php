<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

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
// 2. Azure Blob config
// ---------------------------
$accountName = getenv('AZURE_STORAGE_ACCOUNT');
$accountKey  = getenv('AZURE_STORAGE_KEY');
$container   = getenv('AZURE_STORAGE_CONTAINER');

$connectionString = "DefaultEndpointsProtocol=https;AccountName=$accountName;AccountKey=$accountKey";
$blobClient = BlobRestProxy::createBlobService($connectionString);

// ---------------------------
// 3. Get PR ID
// ---------------------------
$pr_id = $_GET['pr_id'] ?? null;
if (!$pr_id) {
    die("Invalid PRID.");
}

// ---------------------------
// 4. Fetch PR submission
// ---------------------------
$sql = "SELECT * FROM pr_submissions WHERE pr_id = ?";
$params = [$pr_id];
$stmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($stmt);
$feedback = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

// ---------------------------
// 5. Fetch questions
// ---------------------------
$questions = [];
$questions_stmt = sqlsrv_query($conn, "SELECT * FROM questions");
while ($row = sqlsrv_fetch_array($questions_stmt, SQLSRV_FETCH_ASSOC)) {
    $questions[] = $row;
}
$azureBlobBaseUrl = "https://eventsprimagestore.blob.core.windows.net/pr-images/";
// ---------------------------
// 6. Fetch appeal items (if any)
// ---------------------------
$appealItems = [];
$appeal_sql = "SELECT * FROM pr_appeal_items WHERE appeal_id IN 
    (SELECT appeal_id FROM pr_appeals WHERE pr_id = ?)";
$appeal_stmt = sqlsrv_prepare($conn, $appeal_sql, [$pr_id]);
sqlsrv_execute($appeal_stmt);
while ($row = sqlsrv_fetch_array($appeal_stmt, SQLSRV_FETCH_ASSOC)) {
    $appealItems[$row['question_id']] = $row;
}

// Decode reviewer answers
$answers = !empty($feedback['answers']) ? json_decode($feedback['answers'], true) : [];
$reviewerImages = !empty($feedback['image_paths']) ? json_decode($feedback['image_paths'], true) : [];
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
<style>
/* Distinguish appeal images */
.appeal-image {
    border: 2px solid orange;
}
</style>
</head>
<body>
<div class="container">

<?php if ($feedback): ?>
<div class="feedback-card">
    <div class="taskInfo">
        <h3><strong><?= htmlspecialchars($feedback['task_name']) ?></strong></h3>
        <p><strong><?= htmlspecialchars($feedback['pr_id']) ?></strong></p>
        <p><strong>Peer Reviewer:</strong> <?= htmlspecialchars(ucwords($feedback['peer_reviewer_name'])) ?> (<?= htmlspecialchars($feedback['peer_reviewer_email']) ?>)</p>
        <p><strong>Builder:</strong> <?= htmlspecialchars(ucwords($feedback['builder_name'])) ?> (<?= htmlspecialchars($feedback['builder_email']) ?>)</p>
        <p><strong>Date:</strong> <?= htmlspecialchars($feedback['created_at']->format('Y-m-d H:i:s')) ?></p>
    </div>

    <h4>Feedback</h4>
    <ul>
    <?php foreach ($questions as $question): 
        $qid = $question['question_id'];
        $answer = $answers['q'.$qid] ?? null;

        if (!$answer || strtolower($answer) === 'not applicable') continue;
    ?>
        <li>
            <p><strong><?= htmlspecialchars($question['question_text']) ?></strong></p>
            <strong>Peer Reviewer Answer:</strong> <?= htmlspecialchars($answer) ?><br>

            <?php
            if (strtolower($answer) === "applicable") {
                $fatality = $answers['fatality'.$qid] ?? null;
                $fatality_display = $fatality === 'fatal' ? "<span class='highlight'>Fatal Error</span>" :
                                   ($fatality === 'nonFatal' ? "Non-Fatal Error" : "Not specified");
                echo "<strong>Fatality:</strong> $fatality_display<br>";

                $remarks = $answers['remarks'.$qid] ?? 'No remarks provided';
                echo "<strong>Remarks:</strong> " . htmlspecialchars($remarks) . "<br>";
            }

            // ---------------------------
            // Reviewer images from Azure Blob
            // ---------------------------
            if (!empty($reviewerImages['q'.$qid])) {
                echo "<strong>Proof:</strong><br>";
                foreach ($reviewerImages['q'.$qid] as $img) {
                    // Generate Azure Blob URL
        $path = $azureBlobBaseUrl . rawurlencode($img);
                    echo "<img src='$path' class='img-thumbnail preview-image' style='max-width:150px;margin:5px;cursor:pointer;' data-bs-toggle='modal' data-bs-target='#imageModal' data-img-src='$url'>";
                }
            } else {
                echo "<p>No images uploaded.</p>";
            }

            // ---------------------------
            // Appeal images
            // ---------------------------
            if (!empty($appealItems[$qid]['image_paths'])) {
                $appealImgs = json_decode($appealItems[$qid]['image_paths'], true);
                if ($appealImgs) {
                    echo "<br><strong>Builder Appeal Images:</strong><br>";
                    foreach ($appealImgs as $img) {
                        $url = "https://$accountName.blob.core.windows.net/$container/" . urlencode($img);
                        echo "<img src='$url' class='img-thumbnail preview-image appeal-image' style='max-width:150px;margin:5px;cursor:pointer;' data-bs-toggle='modal' data-bs-target='#imageModal' data-img-src='$url'>";
                    }
                }
            }

            ?>
            <hr>
        </li>
    <?php endforeach; ?>
    </ul>
</div>
<?php else: ?>
<div class="alert alert-warning">No feedback found for PRID <?= htmlspecialchars($pr_id) ?>.</div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.preview-image').forEach(img => {
  img.addEventListener('click', () => {
    document.getElementById('modalImage').src = img.dataset.imgSrc;
  });
});
</script>
</body>
</html>
