<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. New Azure SQL Database connection
$connectionOptions = [
    "Database" => "events-pr-db",
    "Uid" => "qmsadmin",
    "PWD" => "Codegenqms!",
    "Encrypt" => true
];
$serverName = "tcp:qms-server.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// Get PRID from URL
$pr_id = $_GET['pr_id'] ?? null;

// Fetch data
if ($pr_id) {
    $sql = "SELECT * FROM pr_submissions WHERE pr_id = ?";
$stmt = sqlsrv_query($conn, $sql, array($pr_id));
$feedback = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    $answers = !empty($feedback['answers']) ? json_decode($feedback['answers'], true) : [];
    $questions_result = sqlsrv_query($conn, "SELECT * FROM questions");
} else {
    $conditions = [];
    $params = [];
    $types = '';

    // Status filter
    if (!empty($_GET['status'])) {
        $conditions[] = "status = ?";
        $params[] = $_GET['status'];
        $types .= 's';
    }

    // Builder name filter (partial match)
    if (!empty($_GET['builder'])) {
        $conditions[] = "builder_name LIKE ?";
        $params[] = '%' . $_GET['builder'] . '%';
        $types .= 's';
    }

    // Submission date filter
    if (!empty($_GET['submission_date'])) {
        $conditions[] = "DATE(submission_date) = ?";
        $params[] = $_GET['submission_date'];
        $types .= 's';
    }

    $sql = "SELECT * FROM pr_submissions";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY submission_date DESC";

    $stmt = $mysqli->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
}

// Fetch appeal data for this PR
$appeal_items = [];
$appeal_sql = "
    SELECT ai.*, a.pr_id 
    FROM pr_appeal_items ai
    JOIN pr_appeals a ON ai.appeal_id = a.appeal_id
    WHERE a.pr_id = ?
";
$appeal_query = sqlsrv_query($conn, $appeal_sql, array($pr_id));

if ($appeal_query !== false) {
    while ($row = sqlsrv_fetch_array($appeal_query, SQLSRV_FETCH_ASSOC)) {
    $qid = $row['question_id'];

    // Decode JSON image paths
    $images = !empty($row['image_paths']) ? json_decode($row['image_paths'], true) : [];

    $appeal_items[$qid] = [
        "builder_answer" => $row["builder_answer"],
        "explanation" => $row["explanation"],
        "image_paths" => $images
    ];
  }
}

$appeal_query->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PR Feedback</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="pr_feedback.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container">
<header>
    <h1>Peer Review Feedback</h1>
    <p>Review the submitted feedback and responses.</p>
</header>

<?php if (!$pr_id): ?>
<!-- Filter and Search Buttons -->
<button id="toggleFilterBtn" type="button" class="btn btn-outline-secondary mb-3">
    <i class="bi bi-filter"></i> Filters
</button>
<button id="toggleSearchBtn" type="button" class="btn btn-outline-secondary mb-3">
    <i class="bi bi-search"></i> Search PRID
</button>

<!-- Filter Form: Hidden by default -->
<form method="GET" action="pr_feedback.php" class="mb-3 row g-2 align-items-end" id="filterForm" style="display:none;">
    <div class="col-md-3">
        <label for="statusFilter" class="form-label">Status</label>
        <select name="status" id="statusFilter" class="form-select">
            <option value="">All</option>
            <option value="Pending - Builder Notified" <?= ($_GET['status'] ?? '') === 'Pending - Builder Notified' ? 'selected' : '' ?>>Pending - Builder Notified</option>
            <option value="Completed - Valid" <?= ($_GET['status'] ?? '') === 'Completed - Valid' ? 'selected' : '' ?>>Completed - Valid</option>
            <option value="Completed - Invalid" <?= ($_GET['status'] ?? '') === 'Completed - Invalid' ? 'selected' : '' ?>>Completed - Invalid</option>
            <option value="Other" <?= ($_GET['status'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
    </div>
    <div class="col-md-3">
        <label for="builderFilter" class="form-label">Builder Name</label>
        <input type="text" name="builder" id="builderFilter" class="form-control" value="<?= htmlspecialchars($_GET['builder'] ?? '') ?>" placeholder="Builder Name">
    </div>
    <div class="col-md-3">
        <label for="dateFilter" class="form-label">Submission Date</label>
        <input type="date" name="submission_date" id="dateFilter" class="form-control" value="<?= htmlspecialchars($_GET['submission_date'] ?? '') ?>">
    </div>
    <div class="col-md-3">
        <button class="btn btn-primary w-100" type="submit">Filter</button>
        <?php if (!empty($_GET['status']) || !empty($_GET['builder']) || !empty($_GET['submission_date'])): ?>
            <a href="pr_feedback.php" class="btn btn-secondary w-100 mt-2">Clear</a>
        <?php endif; ?>
    </div>
</form>

<!-- Search PRID Input: Hidden by default -->
<form method="GET" action="pr_feedback.php" id="searchPridForm" style="display:none;" class="mb-3">
    <div class="d-flex align-items-center gap-2">
        <label for="searchPridInput" class="form-label mb-0" style="font-size:1.1rem; cursor: default;">🔍 PRID :</label>
        <input type="text" name="pr_id" id="searchPridInput" class="form-control" placeholder="Enter PRID" style="max-width: 250px;">
        <button type="submit" class="btn btn-primary">Search</button>
    </div>
</form>

<script>
    const filterBtn = document.getElementById('toggleFilterBtn');
    const searchBtn = document.getElementById('toggleSearchBtn');
    const filterForm = document.getElementById('filterForm');
    const searchForm = document.getElementById('searchPridForm');

    filterBtn.addEventListener('click', function() {
        if (filterForm.style.display === 'none' || filterForm.style.display === '') {
            filterForm.style.display = 'flex';   // Show filter form
            searchForm.style.display = 'none';   // Hide search form
        } else {
            filterForm.style.display = 'none';
        }
    });

    searchBtn.addEventListener('click', function() {
        if (searchForm.style.display === 'none' || searchForm.style.display === '') {
            searchForm.style.display = 'block';  // Show search form
            filterForm.style.display = 'none';   // Hide filter form
        } else {
            searchForm.style.display = 'none';
        }
    });
</script>

<?php endif; ?>

<?php if ($pr_id): ?>
<a href="pr_feedback.php" class="btn btn-secondary mb-4">Back to All Feedback</a>

<?php if ($feedback): ?>
<div class="feedback-card">
    <div class="taskInfo">
        <h3><strong><?= htmlspecialchars($feedback['task_name']) ?></strong></h3>
        <p><strong><?= htmlspecialchars($feedback['pr_id']) ?></strong></p>
        <p><strong>Status:</strong> 
        <?php
        $status = $feedback['status'];
        $status_colors = [
            "Completed - Valid" => ['icon' => 'bi bi-check-circle-fill', 'bg' => '#28a745', 'color' => '#fff'],
            "Completed - Invalid" => ['icon' => 'bi bi-x-circle-fill', 'bg' => '#dc3545', 'color' => '#fff'],
            "Pending - Builder Notified" => ['icon' => 'bi bi-arrow-repeat bi-spin', 'bg' => '#ffc107', 'color' => '#212529'],
            "Other" => ['icon' => 'bi bi-hourglass-split', 'bg' => '#ffc107', 'color' => '#212529'],
        ];
        $icon = $status_colors[$status]['icon'] ?? 'bi bi-hourglass-split';
        $bg = $status_colors[$status]['bg'] ?? '#ffc107';
        $color = $status_colors[$status]['color'] ?? '#212529';
        echo "<span id='prStatus' style='display:inline-block;padding:5px 12px;border-radius:12px;color:$color;font-weight:bold;font-size:0.9rem;background-color:$bg;'><i class='$icon' style='margin-right:5px;'></i>" . htmlspecialchars($status) . "</span>";
        ?>
        </p>
        <p><strong>Peer Reviewer:</strong> 
    		<?= htmlspecialchars(ucwords($feedback['peer_reviewer_name'] ?? '')) ?>
    		(<?= htmlspecialchars($feedback['peer_reviewer_email'] ?? '') ?>)
		</p>

		<p><strong>Builder:</strong> 
    		<?= htmlspecialchars(ucwords($feedback['builder_name'] ?? '')) ?>
    		(<?= htmlspecialchars($feedback['builder_email'] ?? '') ?>)
		</p>

        <p><strong>Date:</strong> <?= htmlspecialchars($feedback['submission_date']) ?></p>
    </div>

    <h4>Feedback</h4>
<ul class="list-group">
<?php 
// Decode review_status JSON from the PR submission
$review_statuses = [];
if (!empty($feedback['review_status'])) {
    $review_statuses = json_decode($feedback['review_status'], true) ?: [];
}


while ($question = sqlsrv_fetch_array($questions_result, SQLSRV_FETCH_ASSOC)) {
    $qid = $question['question_id'];

    // Original reviewer answer
    $answer = $answers['q'.$qid] ?? null;

    // Admin review status (valid/invalid) for this question
    $review_status = $review_statuses['q'.$qid] ?? null;

    // Only display questions with an answer (skip 'Not Applicable')
    if ($answer && strtolower($answer) !== 'not applicable') {

        echo "<li class='list-group-item mb-3 shadow-sm p-4' style='border-radius:10px; position:relative;'>";

        // Header Row: Question + Right-aligned Valid/Invalid buttons or badge
        echo "<div class='d-flex justify-content-between align-items-start'>
                <div style='max-width:70%;'>
                    <h5 class='mb-2' style='font-weight:600;'>" . htmlspecialchars($question['question_text']) . "</h5>
                    <p class='mb-2'><strong>Reviewer Answer:</strong> 
                        <span class='text-primary'>" . htmlspecialchars($answer) . "</span>
                    </p>
                </div>
                <div class='text-end'>";

            $review_status = $review_statuses['q'.$qid] ?? null;

            if ($review_status) {
                // Admin already reviewed → show badge
                $badgeClass = $review_status === 'valid' ? 'bg-success' : 'bg-danger';
                $badgeText  = $review_status === 'valid' ? 'Admin marked as Valid ✓' : 'Admin marked as Invalid ✗';
                echo "<span class='badge $badgeClass p-2' style='font-size:0.95rem;'>$badgeText</span>";
            } else {
            // Show buttons for admin to review
            echo "<div class='btn-group' role='group'>
                    <input type='radio' id='valid_$qid' name='valid_invalid[$qid]' value='valid' 
                           class='d-none question-answer' data-qid='$qid'>
                    <label for='valid_$qid' class='btn btn-outline-success toggle-valid' data-qid='$qid'>✓ Valid</label>

                    <input type='radio' id='invalid_$qid' name='valid_invalid[$qid]' value='invalid' 
                           class='d-none question-answer' data-qid='$qid'>
                    <label for='invalid_$qid' class='btn btn-outline-danger toggle-invalid' data-qid='$qid'>✗ Invalid</label>
                  </div>";
        }
        
    if (!empty($appeal_items[$qid])): 
    echo "<img class='appeal-icon'
        src='https://eventsprguide.infinityfree.me/img/appeal.png'
        style='width:24px;height:24px;cursor:pointer;margin-left:10px'
        data-bs-toggle='modal'
        data-bs-target='#appealModal'
        data-qid='$qid'
        data-bs-toggle='tooltip'
        title='View Appeal'
    >";
endif;

        echo "</div></div>";

        // Fatality + Remarks (only if answer is "Applicable")
        if (strtolower($answer) === "applicable") {
            $fatality = $answers['fatality'.$qid] ?? null;
            $fatality_display = 
                $fatality === 'fatal' ? "<span class='badge bg-danger'>Fatal Error</span>" :
                ($fatality === 'nonFatal' ? "<span class='badge bg-warning text-dark'>Non-Fatal Error</span>" : "Not specified");

            echo "<p class='mt-3'><strong>Fatality:</strong> $fatality_display</p>";

            $remarks = $answers['remarks'.$qid] ?? 'No remarks provided';
            echo "<p><strong>Remarks:</strong> " . htmlspecialchars($remarks) . "</p>";
        }

        // Proof Images
        $images = isset($feedback['image_paths']) ? json_decode($feedback['image_paths'], true) : [];
        echo "<div class='mt-3'><strong>Proof:</strong><br>";

        if (!empty($images['q'.$qid])) {
            foreach ($images['q'.$qid] as $img) {
                $path = '../uploads/' . htmlspecialchars($img);
                echo "<img src='$path' class='img-thumbnail preview-image mt-2'
                     alt='Proof Image'
                     style='max-width:150px;margin-right:10px;cursor:pointer;'
                     data-bs-toggle='modal'
                     data-bs-target='#imageModal'
                     data-img-src='$path'>"; 
            }
        } else {
            echo "<p class='text-muted fst-italic'>No images uploaded.</p>";
        }

        echo "</div>"; // proof container
        echo "</li><hr>";
    }
}
?>
</ul>

<div class="mb-3">
    <?php if ($feedback['status'] !== 'Pending - Builder Notified'): ?>
    <button type="button" class="btn btn-primary" id="sendEmailAjax" data-prid="<?= htmlspecialchars($pr_id) ?>">
        <i class="bi bi-send"></i> Send Email
    </button>
    <?php endif; ?>

    <?php if (empty($feedback['review_status'])): ?>
    <button type="button" class="btn btn-success task-mark-btn" id="markValid" data-status="Completed - Valid">
        Mark Task as Valid
    </button>
    <button type="button" class="btn btn-danger task-mark-btn" id="markInvalid" data-status="Completed - Invalid">
        Mark Task as Invalid
    </button>
<?php endif; ?>

</div>

</div>
<?php else: ?>
<div class="alert alert-warning">No feedback found for PRID <?= htmlspecialchars($pr_id) ?>.</div>
<?php endif; ?>

<?php else: ?>
<div class="feedback-table">
    <h3>All PR Feedbacks</h3>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>PRID</th>
                    <th>Task Name</th>
                    <th>Status</th>
                    <th>Submission Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($feedback = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)): ?>
                <?php $taskName = $feedback['task_name'] ?? '';
					  $shortTask = mb_strimwidth((string)$taskName, 0, 50, '...');
 				?>
                <tr>
                    <td><?= htmlspecialchars($feedback['pr_id']) ?></td>
                    <td title="<?= htmlspecialchars($feedback['task_name'] ?? '') ?>"><?= htmlspecialchars($shortTask) ?></td>
                    <td><?= htmlspecialchars($feedback['status']) ?></td>
                    <td><?= htmlspecialchars($feedback['submission_date']) ?></td>
                    <td><a href="pr_feedback.php?pr_id=<?= htmlspecialchars($feedback['pr_id']) ?>" class="btn btn-info btn-sm">View Feedback</a></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
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
<!-- Appeal Modal -->
<div class="modal fade" id="appealModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Builder Appeal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <p><strong>Builder Answer:</strong> <span id="appealBuilderAnswer"></span></p>
        <p><strong>Builder Explanation:</strong></p>
        <p id="appealExplanation" class="p-2 bg-light rounded"></p>

        <div id="appealImages"></div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>
<!-- Appeal Image Modal -->
<div class="modal fade" id="appealImageModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-dark">
      <div class="modal-body text-center">
        <img id="appealModalImage" src="" class="img-fluid rounded" alt="Appeal Image">
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', async function() {

  const prId = new URLSearchParams(window.location.search).get("pr_id");
  const statusElement = document.getElementById('prStatus');
  let tempReviewStatus = {}; // store Valid/Invalid selections
  let prStatus = ""; // store PR overall status

  // Fetch existing review status from server
  try {
    const res = await fetch(`get_review_status.php?pr_id=${prId}`);
    const data = await res.json();
    if (data.success) {
      tempReviewStatus = data.review_status || {};
      prStatus = data.pr_status || "";
    }
  } catch (err) {
    console.warn("Failed to fetch review status:", err);
  }

  // Update status badge if PR already completed
  if (prStatus) updateStatusBadge(prStatus);

  // Render per-question badges if already reviewed
  Object.entries(tempReviewStatus).forEach(([qid, verdict]) => {
    const label = document.querySelector(`label.toggle-valid[data-qid="${qid}"]`);
    if (!label) return;
    const container = label.parentElement;
    if (!container) return;
    container.innerHTML = verdict === 'valid'
      ? `<span class="badge bg-success">HSME marked as Valid ✓</span>`
      : `<span class="badge bg-danger">HSME marked as Invalid ✗</span>`;
  });

  // Proof images modal
  document.querySelectorAll('.preview-image').forEach(img => {
    img.addEventListener('click', () => {
      document.getElementById('modalImage').src = img.dataset.imgSrc;
    });
  });

  // Track per-question selection
  document.querySelectorAll('.question-answer').forEach(radio => {
    radio.addEventListener('change', () => {
      const qid = radio.dataset.qid;
      tempReviewStatus['q' + qid] = radio.value;

      const validLabel = document.querySelector(`label.toggle-valid[data-qid="${qid}"]`);
      const invalidLabel = document.querySelector(`label.toggle-invalid[data-qid="${qid}"]`);
      if (validLabel && invalidLabel) {
        validLabel.classList.toggle('active', radio.value === 'valid');
        invalidLabel.classList.toggle('active', radio.value === 'invalid');
      }
    });
  });

  // Handle label clicks
  document.querySelectorAll('.toggle-valid, .toggle-invalid').forEach(label => {
    label.addEventListener('click', () => {
      const qid = label.dataset.qid;
      const value = label.classList.contains('toggle-valid') ? 'valid' : 'invalid';
      const radio = document.querySelector(`.question-answer[data-qid="${qid}"][value="${value}"]`);
      if (radio) {
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
      }
    });
  });

  // Finalize PR
  async function finalizePR(newStatus, skipReviewCheck = false) {
    if (!skipReviewCheck && !Object.keys(tempReviewStatus).length) {
      return Swal.fire('Warning', 'Please review at least one question.', 'warning');
    }

    try {
      const res = await fetch('update_status.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          pr_id: prId,
          status: newStatus,
          answers: JSON.stringify({review_status: tempReviewStatus})
        })
      });

      const text = await res.text();
      let data;
      try { data = JSON.parse(text); } catch { data = {success:false,message:text}; }

      if (!data.success) return Swal.fire('Error', data.message || 'Status update failed.', 'error');

      updateStatusBadge(newStatus);

      // Replace per-question buttons with badges
      Object.entries(tempReviewStatus).forEach(([qid, verdict]) => {
        const label = document.querySelector(`label.toggle-valid[data-qid="${qid}"]`);
        if (!label) return;
        const container = label.parentElement;
        if (!container) return;
        container.innerHTML = verdict === 'valid'
          ? `<span class="badge bg-success">Admin marked as Valid ✓</span>`
          : `<span class="badge bg-danger">Admin marked as Invalid ✗</span>`;
      });

      Swal.fire({icon:'success', title:'PR Completed', text:`Marked as "${newStatus}"`, timer:1500, showConfirmButton:false});
    } catch(err) {
      Swal.fire('Error', `Request failed:<br><pre>${err}</pre>`, 'error');
    }
  }

  // Attach finalizePR to task-mark-btn buttons
  document.querySelectorAll('.task-mark-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      finalizePR(btn.dataset.status);
    });
  });

  // Update PR status badge
  function updateStatusBadge(newStatus) {
    const colors = {
      'Completed - Valid': ['bi bi-check-circle-fill', '#28a745', '#fff'],
      'Completed - Invalid': ['bi bi-x-circle-fill', '#dc3545', '#fff'],
      'Pending - Builder Notified': ['bi bi-arrow-repeat bi-spin', '#ffc107', '#212529'],
      'Other': ['bi bi-hourglass-split', '#ffc107', '#212529']
    };
    const [icon, bg, color] = colors[newStatus] || colors['Other'];
    statusElement.innerHTML = `<i class="${icon}" style="margin-right:5px;"></i>${newStatus}`;
    statusElement.style.display = 'inline-block';
    statusElement.style.padding = '5px 12px';
    statusElement.style.borderRadius = '12px';
    statusElement.style.fontWeight = 'bold';
    statusElement.style.fontSize = '0.9rem';
    statusElement.style.color = color;
    statusElement.style.backgroundColor = bg;
  }

  // Send Email
  document.getElementById('sendEmailAjax')?.addEventListener('click', async () => {
    Swal.fire({title:"Sending Email...", text:"Please wait...", allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
    try {
      const res = await fetch('send_email.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({pr_id:prId, ajax:1})
      });
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); } catch { data = {success:false,message:text||"Invalid response"}; }
      Swal.close();
      if(data.success){
        Swal.fire("✅ Success", data.message, "success");
        await finalizePR("Pending - Builder Notified", true);
      } else Swal.fire("❌ Failed", data.message||"Email failed", "error");
    } catch(err){ Swal.close(); Swal.fire("Error", err, "error"); }
  });

  // Initialize tooltips
  var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  // Appeal modal logic
  const appealData = <?= json_encode($appeal_items) ?>;
  document.querySelectorAll('.appeal-icon').forEach(icon => {
    icon.addEventListener('click', () => {
      const qid = icon.dataset.qid;
      const appeal = appealData[qid];

      document.getElementById('appealBuilderAnswer').textContent =
          appeal.builder_answer || "No answer provided";
      document.getElementById('appealExplanation').textContent =
          appeal.explanation || "No explanation provided";

      const imgContainer = document.getElementById('appealImages');
      imgContainer.innerHTML = "";

      if (appeal.image_paths && appeal.image_paths.length > 0) {
        appeal.image_paths.forEach(img => {
          const el = document.createElement("img");
          el.src = "../uploads/" + img;
          el.className = "img-thumbnail me-2 mb-2";
          el.style.maxWidth = "150px";
          el.style.cursor = "pointer";

          // Open appeal image modal
          el.addEventListener('click', () => {
            document.getElementById('appealModalImage').src = el.src;
            new bootstrap.Modal(document.getElementById('appealImageModal')).show();
          });

          imgContainer.appendChild(el);
        });
      } else {
        imgContainer.innerHTML = "<p class='text-muted'>No appeal images.</p>";
      }
    });
  });

});
</script>

</body>
</html>
