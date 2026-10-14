<?php
/* core.php is two folders up, in config/. This read __DIR__.'/core.php', which
   is database/seeds/core.php and has never existed, so the seed fatalled on
   its first line and the Director has always had to be made by hand. */
require_once __DIR__.'/../../config/core.php'; $conn=db();
$exists = $conn->query("SELECT COUNT(*) c FROM users WHERE user_role='super_admin'")->fetch_assoc()['c'] ?? 0;
if ($exists> 0) { exit('A super admin already exists. Delete this file.'); }
$username='superadmin'; $email='superadmin@example.com'; $full='Super Admin'; $pass='Admin123!';
$stmt = $conn->prepare("INSERT INTO users (username,email,password,full_name,user_role,is_active) VALUES (?,?,?,?, 'super_admin', 1)");
$hash = password_hash($pass, PASSWORD_DEFAULT); $stmt->bind_param('ssss', $username,$email,$hash,$full); $stmt->execute();
?><!doctype html><html><body><p>Super Admin created.</p><p><strong>Username:</strong> <?= e($username) ?> / <strong>Password:</strong> <?= e($pass) ?></p><p><em>Delete this file now.</em></p></body></html>
