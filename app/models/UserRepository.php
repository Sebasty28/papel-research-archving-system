<?php
class UserRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    /* The role argument on the four methods below is a guard, not a lookup: it
       stops one console acting on an account belonging to another. It accepts a
       list now, because the Research Coordinator's page manages advisers and
       librarians together and a single role could not express that. */
    private function roleGuard($role): array {
        $roles = is_array($role) ? array_values($role) : [$role];
        return [implode(',', array_fill(0, count($roles), '?')), $roles,
                str_repeat('s', count($roles))];
    }

    private function runGuarded(string $sql, array $head, string $headTypes, $role): bool {
        [$marks, $roles, $roleTypes] = $this->roleGuard($role);
        $stmt = $this->conn->prepare(str_replace('{roles}', $marks, $sql));
        $args = array_merge($head, $roles);
        $refs = [$headTypes . $roleTypes];
        foreach ($args as $k => $_) { $refs[] = &$args[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    public function toggleActiveStatus($userId, $role) {
        return $this->runGuarded(
            "UPDATE users SET is_active = NOT is_active WHERE user_id=? AND user_role IN ({roles})",
            [$userId], 'i', $role);
    }

    public function deleteUser($userId, $role) {
        return $this->runGuarded(
            "DELETE FROM users WHERE user_id=? AND user_role IN ({roles})",
            [$userId], 'i', $role);
    }

    /**
     * The password itself is deliberately not kept.
     *
     * Every account used to be stored twice: once hashed, and once in clear
     * text so the management consoles could print it in a Password column.
     * That put every password in the system — including any a person had
     * reused elsewhere — in reach of anyone at the screen or holding a copy of
     * the database. Only the hash is written now. Whoever performs a reset
     * types the new password, so they already know it; nobody else needs to.
     */
    public function updatePassword($userId, $role, $hash) {
        return $this->runGuarded(
            "UPDATE users SET password=? WHERE user_id=? AND user_role IN ({roles})",
            [$hash, $userId], 'si', $role);
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
        $stmt = $this->conn->prepare("INSERT INTO users (username, email, password, full_name, title, faculty_id, birthdate, user_role, created_by, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param('ssssssssi',
            $data['username'], $data['email'], $data['hash'],
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
                "UPDATE users SET full_name=?, email=?, faculty_id=?, password=?
                 WHERE user_id=? AND user_role IN ('faculty','librarian')");
            $stmt->bind_param('ssssi', $data['full_name'], $data['email'], $data['faculty_id'],
                              $hash, $userId);
        } else {
            $stmt = $this->conn->prepare(
                "UPDATE users SET full_name=?, email=?, faculty_id=?
                 WHERE user_id=? AND user_role IN ('faculty','librarian')");
            $stmt->bind_param('sssi', $data['full_name'], $data['email'], $data['faculty_id'], $userId);
        }
        return $stmt->execute();
    }

    /**
     * Accounts holding any of several roles, at a given active/archived state.
     *
     * The single-role version below could not answer "advisers and librarians",
     * which is what the Research Coordinator's page now lists.
     */
    public function getUsersByRolesAndStatus(array $roles, $isActive) {
        if (!$roles) { return null; }
        $marks = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $this->conn->prepare(
            "SELECT user_id, full_name, email, username, title, faculty_id,
                    admin_level, created_at, is_active, user_role
             FROM users WHERE user_role IN ($marks) AND is_active = ?
             ORDER BY created_at DESC");
        $args  = array_merge($roles, [$isActive]);
        $types = str_repeat('s', count($roles)) . 'i';
        $refs  = [$types];
        foreach ($args as $k => $_) { $refs[] = &$args[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getUsersByRoleAndStatus($role, $isActive) {
        $stmt = $this->conn->prepare("SELECT user_id, full_name, email, username, title, faculty_id, created_at, is_active, user_role FROM users WHERE user_role=? AND is_active=? ORDER BY created_at DESC");
        $stmt->bind_param('si', $role, $isActive);
        $stmt->execute();
        return $stmt->get_result();
    }
}