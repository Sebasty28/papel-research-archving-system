<?php
// cron/send_notifications.php (run via Task Scheduler or cron every 15 mins)
require_once __DIR__.'/../../config/core.php'; $conn=db();
$now = date('H:i'); // e.g., "10:00"
$targets = ['10:00', '15:30'];
if(!in_array($now, $targets, true)){ exit('Off-window'); }

// Find schedules due at this time
$stmt = $conn->prepare("SELECT schedule_id, user_id, scheduled_time, last_sent FROM notification_schedule WHERE is_active=1 AND scheduled_time=?");
$stmt->bind_param('s', $now); $stmt->execute(); $schedules = $stmt->get_result();

while($s=$schedules->fetch_assoc()){
  $uid = (int)$s['user_id'];
  // Pending items for user role
  $u = get_user($uid); if(!$u) continue;
  if($u['user_role']==='faculty'){
    $q = "SELECT COUNT(*) c FROM research_papers p JOIN users st ON st.user_id=p.uploaded_by WHERE p.current_status='pending_faculty' AND st.created_by={$uid}";
  } elseif($u['user_role']==='admin'){
    $q = "SELECT COUNT(*) c FROM research_papers WHERE current_status='pending_admin'";
  } elseif($u['user_role']==='super_admin'){
    $q = "SELECT COUNT(*) c FROM research_papers WHERE current_status='pending_super_admin'";
  } else { $q = "SELECT 0 c"; }
  $c = $conn->query($q)->fetch_assoc()['c'] ?? 0;
  if($c>0){
    create_notification($uid, null, 'reminder', "You have {$c} paper(s) pending review.");
    send_email($u['email'], 'PAPEL Reminder', "You have {$c} paper(s) pending review.");
  }
  // update last_sent
  $conn->query("UPDATE notification_schedule SET last_sent=NOW() WHERE schedule_id=".$s['schedule_id']);
}
echo 'OK';
