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
        $stmt = $this->conn->prepare("UPDATE users SET password=? WHERE user_id=?");
        $stmt->bind_param('si', $hash, $userId);
        return $stmt->execute() && $stmt->affected_rows> 0;
    }

    public function createAdmin($data, $createdBy) {
        /* Derived from the Employee ID, as on the other staff pages. */
        $data['username'] = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string)$data['faculty_id']));
        if ($data['username'] === '') { $data['username'] = 'staff' . substr(bin2hex(random_bytes(4)), 0, 6); }
        $data['birthdate'] = null;

        /* The form asks for a position; role and level are derived from it, so
           the page never has to know how the database spells the job. */
        $map = staff_position_map($data['position'] ?? '');
        if (!$map || $map['role'] === 'faculty') {
            throw new InvalidArgumentException('Choose a position.');
        }
        $data['role']        = $map['role'];
        $data['admin_level'] = $map['level'];

        $data['full_name'] = normalize_person_name($data['full_name'] ?? '');

        if (!$data['full_name'] || !$data['email'] || !$data['password'] || !$data['faculty_id']) {
            throw new InvalidArgumentException("All fields required.");
        }
        if (strlen($data['password']) < 6 || !preg_match('/[A-Z]/', $data['password']) || !preg_match('/[0-9]/', $data['password'])) {
            throw new InvalidArgumentException("Password must be at least 6 characters and contain at least one uppercase letter and one number.");
        }
        
        $check = $this->conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE username=? OR email=? OR faculty_id=?");
        $check->bind_param('sss', $data['username'], $data['email'], $data['faculty_id']);
        $check->execute();
        if ($check->get_result()->fetch_assoc()['cnt']> 0) throw new InvalidArgumentException("That email or ID is already in use.");

        $title_val = trim($data['position']);
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $is_active = 1;
        
        $stmt = $this->conn->prepare("INSERT INTO users (username,email,password,full_name,faculty_id,birthdate,user_role,created_by,is_active,title,admin_level) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssssissi', $data['username'], $data['email'], $hash, $data['full_name'], $data['faculty_id'], $data['birthdate'], $data['role'], $createdBy, $is_active, $title_val, $data['admin_level']);
        
        if ($stmt->execute()) {
            $emailBody  = email_para('Dear ' . $data['full_name'] . ',');
            $emailBody .= email_para('An account has been created for you on ' . APP_NAME
                        . ', the research repository of PUP Biñan Campus.');
            $emailBody .= email_details([
                'Position' => $title_val,
                'ID'       => $data['faculty_id'],
                'Password' => $data['password'],
            ], ['ID', 'Password']);
            $emailBody .= email_action('Sign in at', BASE_URL . '/archive/index.php');
            $emailBody .= email_para('Please change your password after signing in for the first time.');
            // Reported honestly — see the note in FacultyManagementService.
            $sent = function_exists('send_email')
                 && send_email($data['email'], "Your Admin Account Credentials", $emailBody);
            return $title_val . ($sent
                ? ' account created. Credentials sent to email.'
                : ' account created, but the email could not be sent. Give them their'
                  . ' password yourself, or use Reset to set a new one.');
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

        /* Accepted as typed, written down properly: an all-capitals entry is
           re-cased, anything with a lowercase letter in it is left alone. */
        $fullName = normalize_person_name($data['full_name'] ?? '');
        $email    = trim($data['email'] ?? '');
        $empId    = trim($data['faculty_id'] ?? '');
        $password = $data['password'] ?? '';

        /* Moving somebody between positions is a promotion, not a new account,
           so the role, the level and the title all follow the choice. */
        $map = staff_position_map($data['position'] ?? '');
        if (!$map || $map['role'] === 'faculty') {
            throw new InvalidArgumentException('Choose a position.');
        }
        $role  = $map['role'];
        $level = $map['level'];
        $title = trim($data['position']);

        if (!$fullName || !$email || !$empId) {
            throw new InvalidArgumentException("Full name, ID and email are all required.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format.");
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

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->conn->prepare(
                "UPDATE users SET full_name=?, email=?, faculty_id=?, user_role=?, admin_level=?,
                                  title=?, password=?
                 WHERE user_id=? AND user_role IN ('admin','head_academic','librarian')");
            // name, email, id, role, level, title, hash, user_id
            $stmt->bind_param('ssssissi', $fullName, $email, $empId, $role, $level, $title,
                              $hash, $userId);
        } else {
            $stmt = $this->conn->prepare(
                "UPDATE users SET full_name=?, email=?, faculty_id=?, user_role=?, admin_level=?, title=?
                 WHERE user_id=? AND user_role IN ('admin','head_academic','librarian')");
            $stmt->bind_param('ssssisi', $fullName, $email, $empId, $role, $level, $title, $userId);
        }
        return $stmt->execute();
    }

    public function getAdminsByLevel($level) {
        $res = $this->conn->query("SELECT * FROM users WHERE user_role = 'admin' AND admin_level = " . (int)$level . " ORDER BY is_active DESC, created_at DESC");
        $data = []; if ($res) while($row = $res->fetch_assoc()) $data[] = $row; return $data;
    }

    /**
     * The Director's roll, grouped by the position each account holds.
     *
     * Positions are what the page talks in; roles and levels are what the
     * database holds. Head of Academic Programs collects both kinds of record —
     * the head_academic role and an admin at level 2 — because they are the same
     * job, and listing only one of them hid half the people doing it.
     *
     * @return array position => rows
     */
    public function getStaffByPosition(array $positions): array {
        $out = array_fill_keys($positions, []);
        $res = $this->conn->query(
            "SELECT * FROM users
             WHERE user_role IN ('admin','head_academic','librarian')
             ORDER BY is_active DESC, created_at DESC");
        if (!$res) { return $out; }
        while ($row = $res->fetch_assoc()) {
            $pos = staff_position_of($row);
            if (array_key_exists($pos, $out)) { $out[$pos][] = $row; }
        }
        return $out;
    }
}