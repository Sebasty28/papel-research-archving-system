<?php
/**
 * What Corpus knows about the page it is asked from.
 *
 * Every word of it is written here, on the server, and none is read off the
 * reader's screen. A dashboard, a review desk or a management console is full
 * of other people's names, student numbers, emails and unpublished titles, and
 * the page's own DOM cannot tell those apart from its headings, so the widget
 * sends only the address and this file decides what the address means. The
 * one exception is a published paper (corpus_public_record()), whose record is
 * looked up here, behind the same test the paper's own page makes.
 */
require_once __DIR__ . '/corpus_faq.php';
function corpus_history_key(?array $user): string {
    return 'papel.corpus.history.' . hash('sha256', session_id() . ':' . ($user['user_id'] ?? 'visitor'));
}

/** The page's path relative to the site, without BASE_URL or a query string. */
function corpus_page_path(string $url): string {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $base = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?: '', '/');
    // Case-blind: Apache on Windows serves /CAPSTONE and /capstone as one folder.
    if ($base !== '' && stripos($path, $base . '/') === 0) $path = substr($path, strlen($base) + 1);
    $path = ltrim($path, '/');
    // /archive/ and /archive/index are the same page as /archive/index.php.
    if ($path === '' || $path === 'archive' || $path === 'archive/' || $path === 'archive/index') return 'archive/index.php';
    /* The site is served without the extension (/app/my_library), and every
       key here is written with it, so an address without one would match no
       page at all and quietly fall back to the general guide. */
    if (substr($path, -1) !== '/' && pathinfo($path, PATHINFO_EXTENSION) === '') $path .= '.php';
    return $path;
}

/**
 * Who is asking, by position and never by name. It changes what a page offers
 * (an adviser approves, a Coordinator publishes), so answers about "this page"
 * are wrong without it; the name changes nothing and is not sent.
 */
function corpus_reader_role(?array $u): string {
    if (!$u) return isset($_SESSION['guest_login']) ? 'Library Pass holder (read-only guest)' : 'Visitor (not signed in)';
    if (($u['user_role'] ?? '') === 'guest') return 'Library Pass holder (read-only guest)';
    $position = active_desk_position($u);
    return $position !== '' ? $position : role_label($u);
}

/** The features of a page: what it is for, what is on it and how to use it. Never its data. */
function corpus_page_knowledge(string $url): string {
    $pages = [
        // Public and shared pages
        'archive/index.php' => 'Public Repository: browse and search approved, published research papers. The search box takes up to five comma-separated terms and matches any of them. The keyword strip under the toolbar adds or removes a keyword with one press; View All Keywords opens every keyword in a dialog with a find field, applied on Done. The side panel filters by year, paper type and programme, and can be moved left or right or folded to a rail of icons. Quick Settings (the gear) and the profile menu switch the result view between Cards, Rows, Hardbounds (book covers) and Bookshelf (spines), and the density. Twenty results a page, with a pager at the foot. Pressing a book in Hardbounds or Bookshelf holds it up; drag to turn it, press it for its details, Page Up and Page Down step along the shelf. Signed-in readers can save a paper to My Library with the bookmark. Visitors must sign in to read a paper\'s abstract and manuscript.',
        'archive/home.php' => 'Repository home: browse and search published research papers.',
        'archive/view_paper.php' => 'Paper page: a published paper\'s title, authors, date completed, programme, type, keywords, abstract, written sections and citation details, with buttons to open its manuscript in the PDF viewer and to save it to My Library. A student may need a Librarian\'s grant to open a restricted manuscript: Request access files a request, and once granted a countdown in the top right shows the time left. The Director can archive or restore the paper here.',
        'archive/login.php' => 'Library Pass sign-in: the page a Library Pass email links to, for signing in with the pass or with a PAPEL account.',
        'app/auth/login.php' => 'Sign in: enter your student or faculty ID (or email) and password. Students use the Student tab; all staff, including Librarians and the Director, use the Faculty tab. After a few wrong attempts the account is briefly locked. Forgotten passwords are reset by whoever manages the account, or through Contact Support.',
        'pages/help_center.php' => 'Help Center: answers to common questions about using PAPEL, accounts and uploads, and a way to Contact Support.',
        'pages/about_us.php' => 'About PAPEL: the purpose and scope of the PUP Biñan research repository and archiving system.',
        'pages/privacy.php' => 'Privacy Policy: how PAPEL collects, uses and protects personal information. Summarise only in general terms and point to the page for exact wording.',
        'pages/terms_and_conditions.php' => 'Terms and Conditions: the rules for using PAPEL. Summarise only in general terms and point to the page for exact wording.',
        'pages/contact_support.php' => 'Contact Support: send a support request describing a problem. Staff answer it in the support queue, and the reply arrives as a notification.',
        'pages/change_password.php' => 'Change Password: an account whose password was set by somebody else must choose its own before the rest of the site opens. Enter the current (emailed) password, then a new one twice; the eye shows what was typed. Sign out is the only other way off this page.',
        'pages/settings.php' => 'Settings: tabs for Personal Information, Security (change password) and Appearance (light or dark palette, result view and density, Zen Mode, the view chooser, the accessibility tab). Staff can correct their own full name and email; the Director can also correct their Faculty ID. Students cannot edit their name, email, student number or programme here and must ask their Subject Adviser or Contact Support.',
        'notifications/notification_center.php' => 'Notification Center: every notice sent to this account (submissions, approvals, returns with feedback, manuscript requests, support replies, account and security changes), with ways to mark them read. Most notices are also emailed.',
        'app/my_library.php' => 'My Library: papers this account has saved, shown as Rows, Hardbounds or Bookshelf, with search and sort. Students also have Requests (manuscript access requests waiting or unlocked, with the time left) and History (denied, revoked or expired requests, with Request again on the latest settled one).',
        'app/choose_desk.php' => 'Choose Desk: for staff holding two positions. Pick which position\'s desk to work from; the navbar\'s Switch desk link returns here.',
        'app/support_requests.php' => 'Support Requests: staff read and answer support requests. The Director and Research Coordinator see the whole queue; other staff see the requests routed to them.',

        // Students
        'app/student/student_dashboard.php' => 'Student dashboard: every paper this student has started, each as a card with its status (draft, with the Subject Adviser, with the Research Coordinator, published, returned) and an approval track. A draft that was never submitted has Continue editing and a delete bin; a returned paper shows the reviewer\'s feedback and can be revised and resubmitted. Upload Paper starts a new one. Views and filters work as in the repository.',
        'app/student/student_upload_ai.php' => 'Upload Paper, in four steps: (1) paper details: title, authors, paper type, programme, manuscript type (IMRAD, Full Manuscript, both, or Other), date completed or year only, keywords; Extract with AI can fill these from the PDF; (2) written sections: choose which to submit (Abstract only, any of the six, or Custom sections of your own, for example a Feasibility Study\'s aspects or a thesis\'s chapters), with a Writing View for each; (3) supporting documents: Ethics Clearance, Consent Form, Data Collection Tool, Copyright/IP, plus named extra documents; (4) review and submit. The side pane holds Contents, the PDF and Corpus; tabs can be dragged between panes. Save as draft keeps typed work but no PDFs, which must be attached again later. Large PDFs are compressed in the browser before upload.',
        'app/student/student_upload.php' => 'Upload Research: enter the paper details and attach the required PDF and supporting documents.',
        'app/student/paper_details.php' => 'Paper details: the author\'s own view of a submission: its details, written sections, files, status along the approval chain and any feedback from a reviewer. A returned paper is a draft again and can be revised and resubmitted from here.',
        'app/student/pdf_viewer.php' => 'PDF viewer: reads a manuscript or supporting document the reader is allowed to open. Zoom with the buttons or Ctrl and the mouse wheel, page through, search the text, or open it in a new tab.',
        'app/student/upload_diagnostics.php' => 'Upload diagnostics: for staff, a check of upload limits, Google Drive and database gaps that would stop a submission.',

        // Review desks
        'app/faculty/faculty_review_dashboard.php' => 'Subject Adviser review desk: papers submitted by this adviser\'s students, filtered by status, type, programme and year, with search. Open a paper to read it, then Approve (fill the review checklist, which can be edited for that paper) to forward it to the Research Coordinator, or Return it to the student with written feedback.',
        'app/faculty/faculty_dashboard.php' => 'Subject Adviser dashboard: assigned students, papers waiting for review and recent activity.',
        'app/admin/admin_review_dashboard.php' => 'Research Coordinator review desk: papers the Subject Adviser has approved. Read the paper and the adviser\'s checklist, then Approve to publish it to the Public Repository, or Return it to the student with feedback.',
        'app/faculty/head_review_dashboard.php' => 'Head of Academic Programs desk: read submitted and published research records and their status. This role reads; it does not approve.',
        'app/admin/super_admin_review_dashboard.php' => 'Director dashboard: oversee every paper, including the Archived tab, archive or restore papers, and export reports. The Director is not an approval step. Approved papers older than five years can also be archived automatically.',
        'app/review_paper.php' => 'Review Paper: a submission\'s details, written sections, manuscript files, supporting documents and earlier feedback. The reviewer whose step it is can Approve (the Subject Adviser fills the checklist) or Return with specific feedback; other roles read only.',

        // Management consoles
        'app/faculty/faculty_manage_students.php' => 'Manage Students: a Subject Adviser creates, edits, searches and archives student accounts. A student needs a full name, student number (for example 2026-00001-BN-0), email, programme, academic year and section; the section decides when the account expires, so moving a student up a year renews it. Generate makes a starting password from the name and ID, which is emailed. Import reads a CSV or .xlsx of ID, Name and Email into an editable preview.',
        'app/admin/admin_manage_faculty.php' => 'Manage Faculty: the Research Coordinator creates, edits, searches and archives Subject Adviser and Librarian accounts. A Faculty ID is written like FA0000BN2026. Another Position gives one person a second post. Import reads a CSV or .xlsx of ID, Name, Email and Position.',
        'app/admin/super_admin_manage_admins.php' => 'Manage Admins: the Director creates and edits administrator accounts (Research Coordinator, Head of Academic Programs) and their positions.',
        'app/librarian/librarian_manage_guests.php' => 'Library Passes: the Librarian issues and manages temporary guest passes that let a visitor read the repository.',
        'app/librarian/manuscript_requests.php' => 'Manuscript Requests: the Librarian answers students\' requests to read restricted manuscripts. Pending can be granted for a set time or denied; Granted can be revoked; History keeps every settled request. Search, and export to Excel, PDF, CSV or Word.',
        'app/admin/gdrive_settings.php' => 'Storage Folder: the Director or Research Coordinator connects Google Drive and chooses the folder submitted PDFs are stored in. It checks whether the connected account can add files there, shows connection and folder history, lists the stored PDFs by Google account with an access badge, counts removed PDFs waiting in the Trash folder, and keeps storage and billing reminder dates.',

        // Analytics
        'analytics/analytics_dashboard.php' => 'Analytics: filter submission figures by year, programme, type and status, compare charts and tables, read AI-written insights, and export reports. Only papers actually submitted are counted.',
        'analytics/paper_analysis.php' => 'Paper analysis: research metrics and AI-supported analysis of a selected paper.',
    ];
    return $pages[corpus_page_path($url)] ?? 'A PAPEL page without a specific guide. Answer from the site guide and say when you are unsure.';
}

/**
 * The published paper on screen, if there is one, in plain text.
 *
 * Only for a paper anybody signed in may read (paper_is_readable(): approved
 * or archived) and only for a reader who may read it (signed in or on a pass,
 * as archive/view_paper.php requires). A draft, a paper under review or a
 * returned one is somebody's unpublished work and is never described, even to
 * its own author, because the address alone is what is being trusted here.
 */
function corpus_public_record(string $url, ?array $u): string {
    if (!$u && !isset($_SESSION['guest_login'])) return '';
    $path = corpus_page_path($url);
    parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $q);
    $id = 0;
    if ($path === 'archive/view_paper.php') $id = (int)($q['id'] ?? 0);
    elseif ($path === 'app/student/pdf_viewer.php') {
        $id = (int)($q['paper'] ?? 0);
        $ms = (int)($q['manuscript'] ?? 0);
        if (!$id && $ms > 0) {
            try {
                $s = db()->prepare('SELECT paper_id FROM paper_manuscripts WHERE manuscript_id = ?');
                $s->bind_param('i', $ms);
                $s->execute();
                $id = (int)($s->get_result()->fetch_row()[0] ?? 0);
                $s->close();
            } catch (Throwable $e) { $id = 0; } // a copy without the table simply has no record to give
        }
    }
    if ($id <= 0 || !paper_is_readable($id)) return '';

    $cols = 'title, author_names, year, research_date, abstract, keywords, paper_type, program_category';
    $row = null;
    foreach (["SELECT $cols FROM research_papers WHERE paper_id = ? AND current_status = 'approved'",
              "SELECT $cols FROM papers_archive WHERE paper_id = ?"] as $sql) {
        $s = db()->prepare($sql);
        $s->bind_param('i', $id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if ($row) break;
    }
    if (!$row) return '';

    $plain = static function ($v, int $chars): string {
        $t = html_entity_decode(strip_tags(str_replace(['<br', '</p>'], [' <br', ' </p>'], (string)$v)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        return mb_strlen($t) > $chars ? mb_substr($t, 0, $chars) . '…' : $t;
    };
    $lines = [
        'Title: ' . $plain($row['title'], 300),
        'Authors: ' . $plain($row['author_names'], 300),
        'Date completed: ' . paper_date_display($row['research_date'] ?? null, $row['year'] ?? null),
        'Paper type: ' . paper_type_label($row['paper_type'] ?? ''),
        'Programme: ' . $plain($row['program_category'], 120),
        'Keywords: ' . $plain($row['keywords'], 300),
        'Abstract: ' . $plain($row['abstract'], 2500),
    ];
    return implode("\n", array_filter($lines, static fn($l) => !preg_match('/:\s*$/', $l)));
}

function corpus_site_knowledge(): string {
    return 'PAPEL is the PUP Biñan research archiving system. Students upload papers. The Subject Adviser reviews them first; the Research Coordinator approves and publishes them second. The Head of Academic Programs and Director read records but do not add approval steps. Returning a submitted paper sends it back to draft with feedback. The Public Repository shows approved work. A paper can have IMRAD, Full Manuscript, or both PDF versions, plus supporting documents such as Ethics Clearance, Consent Form, Data Collection Tool and Copyright/IP; these are required for most paper types but optional for a thesis and for a paper type the student typed in under Other. Students ask a Librarian for timed access to restricted manuscripts. The live upload and post limits are supplied separately. Analytics includes AI-generated interpretation of filtered statistics. On every page: the profile menu holds dark mode, How papers are shown, and Zen Mode (hides the page furniture); the bell opens notifications; the Accessibility tab at the edge offers text size, contrast and cursor options; idle sessions end after 30 minutes with a warning five minutes before. Give only guidance appropriate to the current user role; never claim that Corpus has changed an account, paper, approval, or setting.';
}
