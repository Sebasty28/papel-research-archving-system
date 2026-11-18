<?php
/** Shared, public-facing PAPEL guide for Corpus on every site page. */
function corpus_history_key(?array $user): string {
    return 'papel.corpus.history.' . hash('sha256', session_id() . ':' . ($user['user_id'] ?? 'visitor'));
}

function corpus_page_knowledge(string $path): string {
    $path = parse_url($path, PHP_URL_PATH) ?: '';
    $base = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?: '', '/');
    if ($base !== '' && strpos($path, $base . '/') === 0) $path = substr($path, strlen($base) + 1);
    $pages = [
        'app/student/student_dashboard.php' => 'Student dashboard: track drafts, submitted papers, review feedback, and publication status.',
        'app/student/student_upload_ai.php' => 'Upload Paper: enter metadata and written sections, attach the required PDF manuscripts and supporting documents, preview, then submit.',
        'app/student/student_upload.php' => 'Upload Research: enter the paper details and attach the required PDF and supporting documents.',
        'app/student/pdf_viewer.php' => 'PDF viewer: read the current paper document. The file is accessed only if the reader is authorized.',
        'app/student/paper_details.php' => 'Paper details: read a submission, its status and any return feedback. A returned paper is a draft that can be revised and resubmitted.',
        'app/faculty/faculty_review_dashboard.php' => 'Subject Adviser review desk: filter and search assigned papers, inspect them, approve them for the Research Coordinator, or return them with feedback.',
        'app/faculty/faculty_dashboard.php' => 'Subject Adviser dashboard: view assigned students, pending reviews, and recent activity.',
        'app/admin/admin_review_dashboard.php' => 'Research Coordinator review desk: review papers approved by the Subject Adviser, publish approved papers, or return them with feedback.',
        'app/faculty/head_review_dashboard.php' => 'Head of Academic Programs desk: read institutional research records and analytics. This role is not an approval step.',
        'app/admin/super_admin_review_dashboard.php' => 'Director desk: oversee the research archive and administrator accounts. The Director is not an additional paper approval step.',
        'app/review_paper.php' => 'Review Paper: read manuscript details and prior feedback, then approve the current step or return the paper with specific feedback when authorized.',
        'app/faculty/faculty_manage_students.php' => 'Manage Students: create, edit, archive, search, filter, and import student accounts.',
        'app/admin/admin_manage_faculty.php' => 'Manage Faculty: create, edit, archive, search, and import Subject Adviser and Librarian accounts.',
        'app/admin/super_admin_manage_admins.php' => 'Manage Admins: the Director manages administrator accounts and their positions.',
        'app/librarian/librarian_manage_guests.php' => 'Library Passes: the Librarian manages guest access to library material.',
        'app/librarian/manuscript_requests.php' => 'Manuscript Requests: the Librarian reviews requests to read restricted manuscripts.',
        'app/my_library.php' => 'My Library: saved papers and, for students, their manuscript access requests.',
        'analytics/analytics_dashboard.php' => 'Analytics: filter submission figures, compare charts and tables, read AI-generated insights, and export reports.',
        'analytics/paper_analysis.php' => 'Paper analysis: inspect research metrics and AI-supported analysis for the selected paper.',
        'archive/index.php' => 'Public Repository: browse and search approved published research papers.',
        'archive/home.php' => 'Repository home: browse and search research papers.',
        'archive/view_paper.php' => 'Paper view: read a published paper and its available manuscript, citation details, and related record.',
        'pages/help_center.php' => 'Help Center: answers to common PAPEL use and account questions.',
        'pages/about_us.php' => 'About PAPEL: the purpose and scope of the PUP Biñan research repository.',
        'pages/privacy.php' => 'Privacy: read how PAPEL handles personal information.',
        'pages/terms_and_conditions.php' => 'Terms and Conditions: read the rules for using PAPEL.',
        'pages/contact_support.php' => 'Contact Support: send a support request about PAPEL.',
        'pages/change_password.php' => 'Change Password: set a new account password before continuing to the site.',
        'app/support_requests.php' => 'Support Requests: authorized staff triage and answer requests.',
        'pages/settings.php' => 'Settings: manage account preferences and change a password.',
        'app/admin/gdrive_settings.php' => 'Storage Folder: the Director or Research Coordinator sets the connected Google Drive folder used for papers.',
        'app/choose_desk.php' => 'Choose Desk: staff holding two positions choose which role desk to use.',
    ];
    return $pages[ltrim($path, '/')] ?? 'This is a PAPEL page. Use its visible title and sections to answer navigation questions.';
}

function corpus_site_knowledge(): string {
    return 'PAPEL is the PUP Biñan research archiving system. Students upload papers. The Subject Adviser reviews them first; the Research Coordinator approves and publishes them second. The Head of Academic Programs and Director read records but do not add approval steps. Returning a submitted paper sends it back to draft with feedback. The Public Repository shows approved work. A paper can have IMRAD, Full Manuscript, or both PDF versions, plus required supporting documents such as Ethics Clearance, Consent Form, and Data Collection instruments. The live upload and post limits are supplied separately. Analytics includes AI-generated interpretation of filtered statistics. Give only guidance appropriate to the current user role; never claim that Corpus has changed an account, paper, approval, or setting.';
}
