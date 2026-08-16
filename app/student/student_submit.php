<?php
require_once '../../config/core.php'; require_role(['student']); csrf_verify(); $conn=db(); $u=current_user();
$paper_id = (int)($_POST['paper_id'] ?? 0);
if($paper_id<=0){ header('Location: student_dashboard.php'); exit; }

// Check if paper was previously declined (cannot resubmit same paper_id)
$check = $conn->prepare("SELECT 1 FROM approval_workflow WHERE paper_id=? AND status='declined' LIMIT 1");
$check->bind_param('i', $paper_id);
$check->execute();
if($check->get_result()->num_rows> 0){
    flash('error', 'This paper was declined. Please upload a new submission to correct mistakes.');
    header('Location: student_dashboard.php'); exit;
}

// Set status -> pending_faculty
set_status($paper_id, 'pending_faculty');

// Find faculty via creator chain
$faculty_id = creator_of($u['user_id']);
if ($faculty_id) {
  add_workflow($paper_id, $faculty_id, 'faculty', 'pending', '');
  create_notification($faculty_id, $paper_id, 'submission', 'A student submitted a paper for review.');
}
flash('success','Submitted to faculty for review.');
header('Location: student_dashboard.php'); exit;
