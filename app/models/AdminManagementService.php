<?php
class AdminManagementService {
    private $conn;
    public function __construct($conn) { $this->conn = $conn; }

    public function toggleStatus($userId) {
        $stmt = $this->conn->prepare("UPDATE users SET is_active = NOT is_active WHERE user_id=?");
        $stmt->bind_param('i', $userId);
        return $stmt->execute();
    }

    public function deleteUser($userId) {
        $stmt = $this->conn->prepare("DELETE FROM users WHERE user_id=?");
        $stmt->bind_param('i', $userId);
        return $stmt->execute() && $stmt->affected_rows> 0;
    }

    public function resetPassword($userId, $newPassword) {
        if (strlen($newPassword) < 6 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            throw new InvalidArgumentException("Password must be at least 6 characters and contain at least one uppercase letter and one number.");
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->conn->prepare("UPDATE users SET password=?, plain_password=? WHERE user_id=?");
        $stmt->bind_param('ssi', $hash, $newPassword, $userId);
        return $stmt->execute() && $stmt->affected_rows> 0;
    }

    public function createAdmin($data, $createdBy) {
        /* Derived from the Employee ID, as on the other staff pages. */
        $data['username'] = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string)$data['faculty_id']));
        if ($data['username'] === '') { $data['username'] = 'staff' . substr(bin2hex(random_bytes(4)), 0, 6); }
        $data['birthdate'] = null;

        if (!$data['full_name'] || !$data['email'] || !$data['password'] || !$data['role'] || !$data['faculty_id']) {
            throw new InvalidArgumentException("All fields required.");
        }
        if (strlen($data['password']) < 6 || !preg_match('/[A-Z]/', $data['password']) || !preg_match('/[0-9]/', $data['password'])) {
            throw new InvalidArgumentException("Password must be at least 6 characters and contain at least one uppercase letter and one number.");
        }
        
        $check = $this->conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE username=? OR email=? OR faculty_id=?");
        $check->bind_param('sss', $data['username'], $data['email'], $data['faculty_id']);
        $check->execute();
        if ($check->get_result()->fetch_assoc()['cnt']> 0) throw new InvalidArgumentException("That email or ID is already in use.");

        $title_map = ['head_academic' => 'Head of Academic Affairs', 'admin' => 'Research Coordinator', 'librarian' => 'Librarian', 'faculty' => 'Research Adviser'];
        $title_val = $title_map[$data['role']] ?? ucwords(str_replace('_', ' ', $data['role']));
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $is_active = 1;
        
        $stmt = $this->conn->prepare("INSERT INTO users (username,email,password,plain_password,full_name,faculty_id,birthdate,user_role,created_by,is_active,title,admin_level) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssssssssissi', $data['username'], $data['email'], $hash, $data['password'], $data['full_name'], $data['faculty_id'], $data['birthdate'], $data['role'], $createdBy, $is_active, $title_val, $data['admin_level']);
        
        if ($stmt->execute()) {
            $emailBody = "Welcome to " . APP_NAME . "!\n\nYour admin account has been created.\n\nID: {$data['faculty_id']}\nPassword: {$data['password']}\n\nPlease login at: " . BASE_URL . "/app/auth/login.php";
            if(function_exists('send_email')) send_email($data['email'], "Your Admin Account Credentials", $emailBody);
            return "Admin created successfully. Credentials sent to email.";
        }
        throw new Exception("Failed to create admin.");
    }

    /**
     * Correct an existing admin's details.
     *
     * Mirrors updateFaculty on the adviser side: the panel that creates one also
     * edits it, the uniqueness checks skip the row being edited, and a blank
     * password leaves theirs alone. The admin level can move — a coordinator
     * promoted to Head of Academic Programs is the same person, not a new one —
     * so it is editable here where the create form also asks for it.
     */
    public function updateAdmin($data, $userId) {
        $userId = (int)$userId;
        if (!$userId) { throw new InvalidArgumentException("That account could not be found."); }

        $fullName = trim($data['full_name'] ?? '');
        $email    = trim($data['email'] ?? '');
        $empId    = trim($data['faculty_id'] ?? '');
        $level    = (int)($data['admin_level'] ?? 0);
        $password = $data['password'] ?? '';

        if (!$fullName || !$email || !$empId) {
            throw new InvalidArgumentException("Full name, ID and email are all required.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format.");
        }
        if (!in_array($level, [1, 2], true)) {
            throw new InvalidArgumentException("Choose an admin level.");
        }
        if ($password !== '' &&
            (strlen($password) < 6 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password))) {
            throw new InvalidArgumentException("Password must be at least 6 characters and contain at least one uppercase letter and one number.");
        }

        $check = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM users
             WHERE (email=? OR (faculty_id IS NOT NULL AND faculty_id<>'' AND faculty_id=?))
               AND user_id<>?");
        $check->bind_param('ssi', $email, $empId, $userId);
        $check->execute();
        if ((int)$check->get_result()->fetch_assoc()['cnt'] > 0) {
            throw new InvalidArgumentException("Another account already uses that email or ID.");
        }

        // The title follows the level, so a promotion does not leave the old one behind.
        $title = $level === 2 ? 'Head of Academic Affairs' : 'Research Coordinator';

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->conn->prepare(
                "UPDATE users SET full_name=?, email=?, faculty_id=?, admin_level=?, title=?,
                                  password=?, plain_password=?
                 WHERE user_id=? AND user_role='admin'");
            // name, email, id, level, title, hash, plain, user_id
            $stmt->bind_param('sssisssi', $fullName, $email, $empId, $level, $title,
                              $hash, $password, $userId);
        } else {
            $stmt = $this->conn->prepare(
                "UPDATE users SET full_name=?, email=?, faculty_id=?, admin_level=?, title=?
                 WHERE user_id=? AND user_role='admin'");
            $stmt->bind_param('sssisi', $fullName, $email, $empId, $level, $title, $userId);
        }
        return $stmt->execute();
    }

    public function getAdminsByLevel($level) {
        $res = $this->conn->query("SELECT * FROM users WHERE user_role = 'admin' AND admin_level = " . (int)$level . " ORDER BY is_active DESC, created_at DESC");
        $data = []; if ($res) while($row = $res->fetch_assoc()) $data[] = $row; return $data;
    }
}