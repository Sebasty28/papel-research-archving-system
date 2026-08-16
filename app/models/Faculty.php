<?php
// app/models/Faculty.php

/**
 * Toggles the active status of a faculty user.
 *
 * @param mysqli $conn The database connection.
 * @param int $user_id The ID of the user to update.
 * @return bool True on success, false on failure.
 */
function toggle_faculty_status(mysqli $conn, int $user_id): bool {
    $stmt = $conn->prepare("UPDATE users SET is_active = NOT is_active WHERE user_id=? AND user_role='faculty'");
    $stmt->bind_param('i', $user_id);
    return $stmt->execute() && $stmt->affected_rows> 0;
}

/**
 * Deletes a faculty user permanently.
 *
 * @param mysqli $conn The database connection.
 * @param int $user_id The ID of the user to delete.
 * @return bool True on success, false on failure.
 */
function delete_faculty(mysqli $conn, int $user_id): bool {
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id=? AND user_role='faculty'");
    $stmt->bind_param('i', $user_id);
    return $stmt->execute() && $stmt->affected_rows> 0;
}

/**
 * Resets a faculty user's password.
 *
 * @param mysqli $conn The database connection.
 * @param int $user_id The ID of the user.
 * @param string $new_pass The new plain text password.
 * @return bool True on success, false on failure.
 */
function reset_faculty_password(mysqli $conn, int $user_id, string $new_pass): bool {
    $hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password=?, plain_password=? WHERE user_id=? AND user_role='faculty'");
    $stmt->bind_param('ssi', $hash, $new_pass, $user_id);
    return $stmt->execute() && $stmt->affected_rows> 0;
}

/**
 * Checks if a username or email already exists.
 *
 * @param mysqli $conn The database connection.
 * @param string $username The username to check.
 * @param string $email The email to check.
 * @return bool True if exists, false otherwise.
 */
function faculty_username_or_email_exists(mysqli $conn, string $username, string $email): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE username=? OR email=?");
    $stmt->bind_param('ss', $username, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['cnt']> 0;
}

/**
 * Checks if a faculty ID already exists.
 *
 * @param mysqli $conn The database connection.
 * @param string $faculty_id The faculty ID to check.
 * @return bool True if exists, false otherwise.
 */
function faculty_id_exists(mysqli $conn, string $faculty_id): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE faculty_id=?");
    $stmt->bind_param('s', $faculty_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['cnt']> 0;
}

/**
 * Creates a new faculty user.
 *
 * @param mysqli $conn The database connection.
 * @param array $data The faculty data.
 * @return bool True on success, false on failure.
 */
function create_faculty(mysqli $conn, array $data): bool {
    $pass = $data['password'];
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare(
        "INSERT INTO users (username, email, password, plain_password, full_name, title, faculty_id, user_role, created_by, is_active) 
         VALUES (?, ?, ?, ?, ?, ?, ?, 'faculty', ?, 1)"
    );
    $stmt->bind_param(
        'sssssssi',
        $data['username'],
        $data['email'],
        $hash,
        $pass,
        $data['full_name'],
        $data['title'],
        $data['faculty_id'],
        $data['created_by']
    );
    
    return $stmt->execute();
}

/**
 * Fetches all faculty members.
 *
 * @param mysqli $conn The database connection.
 * @param bool $active True to fetch active, false for inactive.
 * @return mysqli_result|false The result set or false on failure.
 */
function get_faculty(mysqli $conn, bool $active) {
    $sql = "SELECT user_id, full_name, email, username, plain_password, title, faculty_id, created_at, is_active, user_role 
            FROM users 
            WHERE user_role='faculty' AND is_active=" . ($active ? 1 : 0) . " 
            ORDER BY created_at DESC";
    return $conn->query($sql);
}

/**
 * Gets notifications for a user.
 *
 * @param mysqli $conn
 * @param integer $user_id
 * @return mysqli_result|false
 */
function get_notifications(mysqli $conn, int $user_id) {
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Gets unread notification count for a user.
 *
 * @param mysqli $conn
 * @param integer $user_id
 * @return integer
 */
function get_unread_notification_count(mysqli $conn, int $user_id): int {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id=? AND is_read=0");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['count'] ?? 0;
}