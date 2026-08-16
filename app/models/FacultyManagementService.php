<?php
require_once __DIR__ . '/../models/UserRepository.php';

class FacultyManagementService {
    private $userRepo;

    public function __construct($dbConnection) {
        $this->userRepo = new UserRepository($dbConnection);
    }

    public function toggleFacultyStatus($userId) {
        return $this->userRepo->toggleActiveStatus($userId, 'faculty');
    }

    public function deleteFaculty($userId) {
        return $this->userRepo->deleteUser($userId, 'faculty');
    }

    public function resetFacultyPassword($userId, $newPassword) {
        if (strlen($newPassword) < 6 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            throw new InvalidArgumentException("Password must be at least 6 characters and contain at least one uppercase letter and one number.");
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        return $this->userRepo->updatePassword($userId, 'faculty', $hash, $newPassword);
    }

    /**
     * Correct an existing adviser's details.
     *
     * The same panel that creates one also edits it, so this takes the same
     * fields — with two differences: the uniqueness checks have to ignore the
     * row being edited, and a blank password means "leave it alone" rather than
     * "reject this", since an adviser's password is not something you should
     * have to retype in order to fix a spelling of their name.
     */
    public function updateFaculty($data, $userId) {
        $userId = (int)$userId;
        if (!$userId) { throw new InvalidArgumentException("That account could not be found."); }

        $data['full_name']  = trim($data['full_name'] ?? '');
        $data['email']      = trim($data['email'] ?? '');
        $data['faculty_id'] = trim($data['faculty_id'] ?? '');
        $password           = $data['password'] ?? '';

        if (!$data['full_name'] || !$data['email'] || !$data['faculty_id']) {
            throw new InvalidArgumentException("Full name, Faculty ID and email are all required.");
        }
        if (!preg_match('/^[A-Za-z\s.]+$/', $data['full_name'])) {
            throw new InvalidArgumentException("Full name cannot contain numbers.");
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format.");
        }
        if ($this->userRepo->isEmailOrFacultyIdTaken($data['email'], $data['faculty_id'], $userId)) {
            throw new InvalidArgumentException("Another account already uses that email or Faculty ID.");
        }
        if ($password !== '' &&
            (strlen($password) < 6 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password))) {
            throw new InvalidArgumentException("Password must be at least 6 characters and contain at least one uppercase letter and one number.");
        }

        return $this->userRepo->updateFacultyDetails($userId, $data, $password);
    }

    public function createFaculty($data, $createdBy) {
        /* Derived from the Faculty ID: nobody types a username any more, but the
           column is still unique and still wants a value. */
        $data['username'] = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string)$data['faculty_id']));
        if ($data['username'] === '') { $data['username'] = 'staff' . substr(bin2hex(random_bytes(4)), 0, 6); }
        $data['birthdate'] = null;

        if (!$data['full_name'] || !$data['email'] || !$data['password'] || !$data['title'] || !$data['faculty_id']) {
            throw new InvalidArgumentException("All fields are required.");
        }
        if (!preg_match('/^[A-Za-z\s.]+$/', $data['full_name'])) {
            throw new InvalidArgumentException("Full name cannot contain numbers.");
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format.");
        }
        if (strlen($data['password']) < 6 || !preg_match('/[A-Z]/', $data['password']) || !preg_match('/[0-9]/', $data['password'])) {
            throw new InvalidArgumentException("Password must be at least 6 characters and contain at least one uppercase letter and one number.");
        }
        if ($this->userRepo->isUsernameOrEmailExists($data['username'], $data['email'])) {
            throw new InvalidArgumentException("Username or email already exists.");
        }
        if ($this->userRepo->isFacultyIdExists($data['faculty_id'])) {
            throw new InvalidArgumentException("Faculty ID already exists. Please use a different Faculty ID.");
        }

        $userRole = 'faculty';
        if ($data['title'] === 'Head of Academic Affairs') {
            $userRole = 'head_academic';
        } elseif ($data['title'] === 'Librarian') {
            $userRole = 'librarian';
        }

        $data['hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        $data['plain_password'] = $data['password'];
        $data['user_role'] = $userRole;
        $data['created_by'] = $createdBy;

        if ($this->userRepo->createUser($data)) {
            return $this->sendWelcomeEmail($data);
        }
        throw new Exception("Failed to create account in the database.");
    }

    private function sendWelcomeEmail($userData) {
        $emailBody = "Welcome to " . APP_NAME . "!\n\nYour account has been created.\n\nRole: " . $userData['title'] . "\nFaculty ID: " . $userData['faculty_id'] . "\nPassword: " . $userData['plain_password'] . "\n\nSign in with your Faculty ID at: " . BASE_URL . "/archive/index.php";
        try {
            if (function_exists('send_email')) send_email($userData['email'], "Your Account Credentials", $emailBody);
            return $userData['title'] . ' account created and credentials sent to email.';
        } catch (Exception $e) {
            return $userData['title'] . ' account created, but email failed: ' . $e->getMessage();
        }
    }

    public function getActiveFaculty() { return $this->userRepo->getUsersByRoleAndStatus('faculty', 1); }
    public function getInactiveFaculty() { return $this->userRepo->getUsersByRoleAndStatus('faculty', 0); }
}