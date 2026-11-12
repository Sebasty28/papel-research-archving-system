<?php
require_once __DIR__ . '/../models/UserRepository.php';

class FacultyManagementService {
    private $userRepo;

    public function __construct($dbConnection) {
        $this->userRepo = new UserRepository($dbConnection);
    }

    /* Advisers and librarians are both created and managed from the Research
       Coordinator's console, so every operation here accepts either. */
    private const MANAGES = ['faculty', 'librarian'];

    public function toggleFacultyStatus($userId) {
        return $this->userRepo->toggleActiveStatus($userId, self::MANAGES);
    }

    public function deleteFaculty($userId) {
        return $this->userRepo->deleteUser($userId, self::MANAGES);
    }

    public function resetFacultyPassword($userId, $newPassword) {
        if (strlen($newPassword) < 6 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            throw new InvalidArgumentException("Password must be at least 6 characters and contain at least one uppercase letter and one number.");
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        return $this->userRepo->updatePassword($userId, self::MANAGES, $hash);
    }

    /**
     * Correct an existing adviser's details.
     *
     * The same panel that creates one also edits it, so this takes the same
     * fields, with two differences: the uniqueness checks have to ignore the
     * row being edited, and a blank password means "leave it alone" rather than
     * "reject this", since an adviser's password is not something you should
     * have to retype in order to fix a spelling of their name.
     */
    public function updateFaculty($data, $userId) {
        $userId = (int)$userId;
        if (!$userId) { throw new InvalidArgumentException("That account could not be found."); }

        /* Accepted as typed, written down properly: an all-capitals entry is
           re-cased, anything with a lowercase letter in it is left alone. */
        $data['full_name']  = normalize_person_name($data['full_name'] ?? '');
        $data['email']      = trim($data['email'] ?? '');
        /* An ID is a code, not somebody's typing: FA0001bn2026 and FA0001BN2026
           are the same one, and only one of them is ever stored. */
        $data['faculty_id'] = staff_id_clean($data['faculty_id'] ?? '');
        $password           = $data['password'] ?? '';

        /* What this account's ID is now, read from the database and never from
           the form: an ID issued before the format was settled is allowed to
           stay, so that such an account can still have its name or its email
           corrected, and a posted value cannot be used to claim that licence
           for an ID the account never had. See staff_id_problem(). */
        $keeping = $this->userRepo->facultyIdOf($userId);

        if (!$data['full_name'] || !$data['email'] || !$data['faculty_id']) {
            throw new InvalidArgumentException("Full name, Faculty ID and email are all required.");
        }
        /* One rule per answer, shared with the students console and with the
           other staff console: see "What a management console may be told" in
           config/core.php. The first complaint is the one thrown, so they are
           read in the order the fields are in. */
        foreach ([person_name_problem($data['full_name']),
                  staff_id_problem($data['faculty_id'], 'Faculty ID', $keeping),
                  account_email_problem($data['email'])] as $why) {
            if ($why !== null) { throw new InvalidArgumentException($why); }
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

    /* $notify is false for a spreadsheet import, which asks before it emails
       anybody: forty welcome messages cannot be recalled, and a trial run is
       the likeliest first use. See config/account_import.php. */
    public function createFaculty($data, $createdBy, bool $notify = true) {
        /* An ID is a code, not somebody's typing: FA0001bn2026 and FA0001BN2026
           are the same one, and only one of them is ever stored. Done before
           the username is taken off it, so that is one case too. */
        $data['faculty_id'] = staff_id_clean($data['faculty_id'] ?? '');
        /* Derived from the Faculty ID: nobody types a username any more, but the
           column is still unique and still wants a value. */
        $data['username'] = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string)$data['faculty_id']));
        if ($data['username'] === '') { $data['username'] = 'staff' . substr(bin2hex(random_bytes(4)), 0, 6); }
        $data['birthdate'] = null;

        $data['full_name'] = normalize_person_name($data['full_name'] ?? '');

        if (!$data['full_name'] || !$data['email'] || !$data['password'] || !$data['title'] || !$data['faculty_id']) {
            throw new InvalidArgumentException("All fields are required.");
        }
        /* One rule per answer, shared with the students console and with the
           other staff console: see "What a management console may be told" in
           config/core.php. The first complaint is the one thrown, so they are
           read in the order the fields are in. */
        foreach ([person_name_problem($data['full_name']),
                  staff_id_problem($data['faculty_id'], 'Faculty ID'),
                  account_email_problem($data['email'])] as $why) {
            if ($why !== null) { throw new InvalidArgumentException($why); }
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

        /* The position chosen on the form decides the role stored. Only the two
           this console offers are accepted: a hand-edited form cannot mint an
           admin from here. */
        $map = staff_position_map($data['title']);
        if (!$map || !in_array($map['role'], self::MANAGES, true)) {
            throw new InvalidArgumentException('Choose a position.');
        }
        $userRole = $map['role'];

        $data['hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        $data['user_role'] = $userRole;
        $data['created_by'] = $createdBy;

        /* A second post, checked against what the person creating the account
           may actually give: this console may not hand out a Research
           Coordinator, which is the escalation that list exists to stop. */
        if (users_have_secondary_position()) {
            $data['secondary_position'] = secondary_position_for(
                $data['actor'] ?? current_user(), $data['secondary_position'] ?? '', $data['title']);
        } else {
            unset($data['secondary_position']);
        }

        if ($this->userRepo->createUser($data)) {
            return $notify
                ? $this->sendWelcomeEmail($data)
                : $data['title'] . ' account created. No email was sent.';
        }
        throw new Exception("Failed to create account in the database.");
    }

    private function sendWelcomeEmail($userData) {
        $emailBody  = email_para('Dear ' . $userData['full_name'] . ',');
        $emailBody .= email_para('An account has been created for you on ' . APP_NAME
                    . ', the research repository of PUP Biñan Campus.');
        $emailBody .= email_details([
            'Position'   => $userData['title'],
            'Faculty ID' => $userData['faculty_id'],
            'Password'   => $userData['password'],
        ], ['Faculty ID', 'Password']);
        $emailBody .= email_action('Sign in with your Faculty ID at', BASE_URL . '/archive/index.php');
        $emailBody .= email_para('Please change your password after signing in for the first time.');
        try {
            /* Say what actually happened. This used to claim the email had
               been sent regardless, so a failing mail server looked like a
               working one and nobody knew to pass the password on by hand. */
            $sent = function_exists('send_email')
                 && send_email($userData['email'], "Your Account Credentials", $emailBody);
            return $userData['title'] . ($sent
                ? ' account created and credentials sent to email.'
                : ' account created, but the email could not be sent. Give them their'
                  . ' password yourself, or use Reset to set a new one.');
        } catch (Exception $e) {
            return $userData['title'] . ' account created, but email failed: ' . $e->getMessage();
        }
    }

    public function getActiveFaculty() { return $this->userRepo->getUsersByRolesAndStatus(self::MANAGES, 1); }
    public function getInactiveFaculty() { return $this->userRepo->getUsersByRolesAndStatus(self::MANAGES, 0); }
}