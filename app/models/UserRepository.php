<?php
class UserRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function toggleActiveStatus($userId, $role) {
        $stmt = $this->conn->prepare("UPDATE users SET is_active = NOT is_active WHERE user_id=? AND user_role=?");
        $stmt->bind_param('is', $userId, $role);
        $stmt->execute();
        return $stmt->affected_rows> 0;
    }

    public function deleteUser($userId, $role) {
        $stmt = $this->conn->prepare("DELETE FROM users WHERE user_id=? AND user_role=?");
        $stmt->bind_param('is', $userId, $role);
        $stmt->execute();
        return $stmt->affected_rows> 0;
    }

    public function updatePassword($userId, $role, $hash, $plainPassword) {
        $stmt = $this->conn->prepare("UPDATE users SET password=?, plain_password=? WHERE user_id=? AND user_role=?");
        $stmt->bind_param('ssis', $hash, $plainPassword, $userId, $role);
        $stmt->execute();
        return $stmt->affected_rows> 0;
    }

    public function isUsernameOrEmailExists($username, $email) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE username=? OR email=?");
        $stmt->bind_param('ss', $username, $email);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['cnt']> 0;
    }

    public function isFacultyIdExists($facultyId) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE faculty_id=?");
        $stmt->bind_param('s', $facultyId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['cnt']> 0;
    }

    public function createUser($data) {
        $stmt = $this->conn->prepare("INSERT INTO users (username, email, password, plain_password, full_name, title, faculty_id, birthdate, user_role, created_by, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param('sssssssssi', 
            $data['username'], $data['email'], $data['hash'], $data['plain_password'], 
            $data['full_name'], $data['title'], $data['faculty_id'], $data['birthdate'], $data['user_role'], $data['created_by']
        );
        return $stmt->execute();
    }

    /** Is this email or Faculty ID already on some other account? */
    public function isEmailOrFacultyIdTaken($email, $facultyId, $exceptUserId) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM users
             WHERE (email=? OR (faculty_id IS NOT NULL AND faculty_id<>'' AND faculty_id=?))
               AND user_id<>?");
        $stmt->bind_param('ssi', $email, $facultyId, $exceptUserId);
        $stmt->execute();
        return (int)$stmt->get_result()->fetch_assoc()['cnt'] > 0;
    }

    /**
     * Save an adviser's details. A blank password leaves the current one alone,
     * so the two statements differ only in whether they touch it.
     */
    public function updateFacultyDetails($userId, $data, $password) {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->conn->prepare(
                "UPDATE users SET full_name=?, email=?, faculty_id=?, password=?, plain_password=?
                 WHERE user_id=? AND user_role='faculty'");
            $stmt->bind_param('sssssi', $data['full_name'], $data['email'], $data['faculty_id'],
                              $hash, $password, $userId);
        } else {
            $stmt = $this->conn->prepare(
                "UPDATE users SET full_name=?, email=?, faculty_id=?
                 WHERE user_id=? AND user_role='faculty'");
            $stmt->bind_param('sssi', $data['full_name'], $data['email'], $data['faculty_id'], $userId);
        }
        return $stmt->execute();
    }

    public function getUsersByRoleAndStatus($role, $isActive) {
        $stmt = $this->conn->prepare("SELECT user_id, full_name, email, username, plain_password, title, faculty_id, created_at, is_active, user_role FROM users WHERE user_role=? AND is_active=? ORDER BY created_at DESC");
        $stmt->bind_param('si', $role, $isActive);
        $stmt->execute();
        return $stmt->get_result();
    }
}