<?php
/**
 * Corpus's ready answers: the chips a new conversation opens with, and what a
 * typed question is checked against before any key in the pool is spent.
 *
 * Written here, like the page guides in corpus_knowledge.php, and chosen by
 * page and by role, so the review desk does not offer "How do I submit a
 * paper?" and a visitor is not told how to approve one. Each answer is plain
 * text in the small Markdown both chat windows draw (**bold**, "- " and "1. "
 * lines). An answer that names a limit reads it from the server, as the AI's
 * prompt does, so the two cannot disagree about how big a PDF may be.
 *
 * Nothing here is about a person or a record: these are the same for every
 * reader of a page, which is what makes it safe to print them into the page.
 *
 * 'page' lists the paths an entry belongs to (offered first there); an entry
 * without one belongs everywhere. 'who' is a test of the reader. 'also' are
 * other wordings a typed question may arrive in.
 */
function corpus_faq_bank(): array {
    $any       = static fn(?array $u): bool => true;
    $visitor   = static fn(?array $u): bool => !$u && !isset($_SESSION['guest_login']);
    $member    = static fn(?array $u): bool => $u && ($u['user_role'] ?? '') !== 'guest';
    $student   = static fn(?array $u): bool => user_has_role($u, 'student');
    $adviser   = static fn(?array $u): bool => user_has_role($u, 'faculty');
    $coord     = static fn(?array $u): bool => is_research_coordinator($u);
    $reviewer  = static fn(?array $u): bool => user_has_role($u, 'faculty') || is_research_coordinator($u);
    $librarian = static fn(?array $u): bool => user_has_role($u, 'librarian');
    $maxUpload = max_upload_label();
    $maxPost   = max_post_label();

    return [
        // Page-specific first, in the order they should be offered.
        ['id' => 'search', 'page' => ['archive/index.php', 'archive/home.php'], 'who' => $any,
         'q' => 'How do I search by keyword?',
         'also' => ['how do i search', 'how to search papers', 'how do i find a paper', 'search by keywords'],
         'a' => "Type up to **five** terms in the search box, separated by commas; a paper matching any of them is shown.\n\n- Press a chip in the **keyword strip** to add or remove that keyword.\n- **View All Keywords** lists every keyword with a find field; choose, then press **Done**.\n- The side panel narrows by year, paper type and programme."],
        ['id' => 'views', 'page' => ['archive/index.php', 'app/student/student_dashboard.php', 'app/my_library.php'], 'who' => $any,
         'q' => 'How do I change how papers are shown?',
         'also' => ['what are hardbounds and bookshelf', 'change the view', 'switch to rows', 'bookshelf view'],
         'a' => "Open the **profile menu** or **Quick Settings** (the gear) and pick a view.\n\n- **Cards**: one card per paper.\n- **Rows**: one compact line each.\n- **Hardbounds**: each paper as a bound book cover.\n- **Bookshelf**: the same books, spines out.\n\nPress a book to hold it up, drag to turn it, and press it again for its details."],
        ['id' => 'save', 'page' => ['archive/view_paper.php', 'archive/index.php', 'app/my_library.php'], 'who' => $member,
         'q' => 'How do I save a paper to My Library?',
         'also' => ['how do i save a paper', 'save this paper', 'bookmark a paper', 'where are my saved papers'],
         'a' => "Press the **bookmark** on the paper, in the repository or on the paper's own page.\n\nSaved papers are listed in **My Library**, second in the navbar. Press the bookmark again to take a paper out."],
        ['id' => 'restricted', 'page' => ['archive/view_paper.php', 'app/my_library.php'], 'who' => $student,
         'q' => "Why can't I open the manuscript?",
         'also' => ['how do i read a restricted manuscript', 'request access to a manuscript', 'manuscript is locked', 'how to request manuscript'],
         'a' => "Some manuscripts need a **Librarian's** permission first.\n\n1. Press **Request access** on the paper's page.\n2. Wait for a Librarian to answer; you are notified either way.\n3. Once granted, a countdown in the top right shows how long the file stays open.\n\nYour requests are under **My Library > Requests**."],
        ['id' => 'extract', 'page' => ['app/student/student_upload_ai.php'], 'who' => $any,
         'q' => 'What does Extract with AI do?',
         'also' => ['extract with ai', 'what is extract with ai', 'auto fill from pdf'],
         'a' => "It reads your attached PDF and fills in the **title, authors, year and keywords** for you.\n\n- The **Abstract** is copied word for word only when it can be found exactly; otherwise your own text is kept.\n- With both IMRAD and Full Manuscript attached, it reads the **IMRAD** file.\n- Check every field afterwards; you can edit anything it filled."],
        ['id' => 'reattach', 'page' => ['app/student/student_upload_ai.php', 'app/student/student_dashboard.php'], 'who' => $student,
         'q' => 'Why do I have to attach my PDFs again?',
         'also' => ['my pdf is gone', 'draft lost my files', 'why are my files missing'],
         'a' => "A draft keeps everything you typed but **no PDFs**: files are stored only once a paper is handed in.\n\nWhen you continue a draft, attach the manuscript and supporting documents again before submitting. A paper returned for revision also needs its files attached again."],
        ['id' => 'files', 'page' => ['app/student/student_upload_ai.php', 'app/student/student_upload.php'], 'who' => $any,
         'q' => 'What files can I upload?',
         'also' => ['file size limit', 'how big can my pdf be', 'maximum file size', 'what is the upload limit'],
         'a' => "**PDF files only**, at most **$maxUpload** each, and **$maxPost** for everything in one submission.\n\n- Larger PDFs are compressed in your browser before upload; **Use original** undoes it.\n- Very large files must be compressed before you attach them.\n- Ticking both IMRAD and Full Manuscript needs one PDF for each."],
        ['id' => 'approve', 'page' => ['app/faculty/faculty_review_dashboard.php', 'app/review_paper.php'], 'who' => $adviser,
         'q' => 'How do I approve a paper?',
         'also' => ['approve a submission', 'forward a paper to the coordinator', 'how to approve'],
         'a' => "Open the paper from your **Review Desk**, read it, then press **Approve**.\n\n1. Tick the **review checklist**; **Edit sections** changes the list for that paper only.\n2. Confirm, and the paper goes to the **Research Coordinator**.\n\nThe student is notified at each step."],
        ['id' => 'return', 'page' => ['app/faculty/faculty_review_dashboard.php', 'app/admin/admin_review_dashboard.php', 'app/review_paper.php'], 'who' => $reviewer,
         'q' => 'How do I return a paper with feedback?',
         'also' => ['return a paper', 'reject a paper', 'send a paper back', 'decline a paper'],
         'a' => "Open the paper and press **Return**, then write what needs to change.\n\nThe paper goes back to the student as a **draft** with your feedback, and they can revise and resubmit it. Its PDFs are moved to the storage folder's **Trash**, so the student attaches them again."],
        ['id' => 'publish', 'page' => ['app/admin/admin_review_dashboard.php', 'app/review_paper.php'], 'who' => $coord,
         'q' => 'How do I publish a paper?',
         'also' => ['publish a paper', 'final approval', 'how to approve as coordinator'],
         'a' => "Open a paper the Subject Adviser has approved, read it with their checklist, and press **Approve**.\n\nApproval ends with you: the paper is **published** to the Public Repository and the student is notified."],
        ['id' => 'import', 'page' => ['app/faculty/faculty_manage_students.php', 'app/admin/admin_manage_faculty.php', 'app/admin/super_admin_manage_admins.php'], 'who' => $member,
         'q' => 'How do I import accounts from a spreadsheet?',
         'also' => ['import students', 'upload a list of students', 'import from excel', 'bulk create accounts'],
         'a' => "Press **Import** beside the search field and choose a **CSV or .xlsx** file with columns **ID, Name, Email** (and **Position** for staff).\n\n1. Check the preview; every cell can be edited, and rows already on an account are greyed.\n2. Tick the email option only if the new accounts should be sent their passwords now.\n3. Press **Create**. Rows that fail stay for you to correct."],
        ['id' => 'renew', 'page' => ['app/faculty/faculty_manage_students.php'], 'who' => $adviser,
         'q' => "How do I renew a student's account?",
         'also' => ['student account expired', 'extend a student account', 'renew account'],
         'a' => "Edit the student and **move their section up a year**, for example 2-1 to 3-1.\n\nAn account's life is counted from its section and academic year and ends on **31 July**, so saving the new section sets a new expiry."],
        ['id' => 'grant', 'page' => ['app/librarian/manuscript_requests.php'], 'who' => $librarian,
         'q' => 'How do I grant manuscript access?',
         'also' => ['grant a request', 'approve a manuscript request', 'revoke access'],
         'a' => "Find the request under **Pending** and grant it, choosing how long the file stays open.\n\n- **Deny** refuses it; the student is told.\n- **Revoke** under **Granted** ends access early.\n- Every settled request is kept under **History**."],
        ['id' => 'profile', 'page' => ['pages/settings.php'], 'who' => $member,
         'q' => 'Can I change my name or email?',
         'also' => ['change my email', 'change my name', 'edit my profile', 'update my email'],
         'a' => "**Staff** can correct their own full name and email under **Settings > Personal Information**.\n\n**Students** cannot: ask your **Subject Adviser** or **Contact Support**, since your name and email are what your adviser's roll is checked against."],

        // Everywhere, for whoever it fits.
        ['id' => 'submit', 'who' => $student,
         'q' => 'How do I submit a paper?',
         'also' => ['how do i upload a paper', 'how to submit research', 'upload my paper', 'how to upload'],
         'a' => "Press **Upload Paper** on your dashboard and work through the four steps.\n\n1. **Paper details**: title, authors, type, programme, manuscript type, date.\n2. **Written sections**: choose which to submit and write them.\n3. **Supporting documents**: Ethics Clearance, Consent Form, Data Collection Tool and others.\n4. **Review and submit**.\n\n**Save as draft** keeps your typing at any point."],
        ['id' => 'status', 'who' => $student,
         'q' => "Where can I see my paper's status?",
         'also' => ['check my paper status', 'where is my paper', 'track my submission', 'has my paper been approved'],
         'a' => "Your **dashboard** lists every paper with its status and an approval track.\n\n- **With the Subject Adviser**, then **with the Research Coordinator**, then **published**.\n- A **returned** paper shows the reviewer's feedback.\n- You are also notified, by the bell and by email, at every step."],
        ['id' => 'returned', 'who' => $student,
         'q' => 'My paper was returned. What now?',
         'also' => ['my paper was rejected', 'paper returned', 'how to resubmit', 'what do i do after my paper is returned'],
         'a' => "A returned paper is a **draft** again, with the reviewer's **feedback** attached.\n\n1. Open it from your dashboard and read the feedback.\n2. Make the changes and **attach your PDFs again**.\n3. Submit; it goes back to your Subject Adviser."],
        ['id' => 'flow', 'who' => $any,
         'q' => 'How does a paper get published?',
         'also' => ['what is the approval process', 'who approves papers', 'approval chain', 'who reviews my paper'],
         'a' => "Two reviewers sign off, in order.\n\n1. The student uploads the paper.\n2. The **Subject Adviser** reviews and approves it.\n3. The **Research Coordinator** approves and **publishes** it to the Public Repository.\n\nEither reviewer can return it with feedback instead."],
        ['id' => 'signin', 'who' => $visitor,
         'q' => 'How do I sign in?',
         'also' => ['how do i log in', 'login', 'sign in', 'how to login'],
         'a' => "Press **Sign in** in the navbar and enter your **ID** (or email) and **password**.\n\n- Students use the **Student** tab; all staff use the **Faculty** tab.\n- Accounts are created by the campus, so there is no sign-up.\n- Forgotten your password? **Contact Support**."],
        ['id' => 'what', 'who' => $visitor,
         'q' => 'What is PAPEL?',
         'also' => ['what is this site', 'what can i do here', 'about papel'],
         'a' => "PAPEL is **PUP Biñan's research repository and archiving system**.\n\nStudents submit papers, the Subject Adviser and Research Coordinator review them, and approved work is published in the **Public Repository** for anyone to browse."],
        ['id' => 'password', 'who' => $member,
         'q' => 'How do I change my password?',
         'also' => ['change password', 'reset my password', 'forgot my password', 'new password'],
         'a' => "Open **Settings > Security**, enter your current password, then the new one twice.\n\nForgotten it? Ask whoever manages your account, or **Contact Support**. Nobody, Corpus included, can read your old one."],
        ['id' => 'dark', 'who' => $any,
         'q' => 'How do I turn on dark mode?',
         'also' => ['dark mode', 'dark theme', 'change the colours', 'change the colors', 'night mode'],
         'a' => "Open the **profile menu** and press **Dark mode**, or choose a palette in **Settings > Appearance**.\n\nThe **Accessibility** tab at the screen edge has more: text size, contrast and a large cursor."],
        ['id' => 'support', 'who' => $any,
         'q' => 'How do I contact support?',
         'also' => ['contact support', 'i need help', 'report a problem', 'talk to a person'],
         'a' => "Open **Contact Support** (in the footer and the Help Center) and describe the problem.\n\nStaff answer in the support queue and the reply arrives as a **notification**."],
    ];
}

/** The ready answers for this page and reader: the page's own first, then the rest. */
function corpus_faqs(string $url, ?array $u): array {
    $path = corpus_page_path($url);
    $here = $elsewhere = [];
    foreach (corpus_faq_bank() as $f) {
        if (!($f['who'])($u)) continue;
        $row = ['id' => $f['id'], 'q' => $f['q'], 'a' => $f['a'], 'also' => $f['also'] ?? []];
        if (isset($f['page'])) { if (in_array($path, $f['page'], true)) $here[] = $row; }
        else $elsewhere[] = $row;
    }
    return array_merge($here, $elsewhere);
}

/** The few offered as chips on a new conversation, with their answers, for printing into the page. */
function corpus_faq_chips(string $url, ?array $u, int $n = 5): array {
    return array_map(static fn($f) => ['id' => $f['id'], 'q' => $f['q'], 'a' => $f['a']],
        array_slice(corpus_faqs($url, $u), 0, $n));
}

/** Lower case, letters and digits only, with the small words a question is padded with taken out. */
function corpus_faq_words(string $s): array {
    $s = mb_strtolower(str_replace(["'", "\u{2019}"], '', $s));
    $words = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    static $skip = ['a', 'an', 'the', 'i', 'me', 'my', 'do', 'does', 'can', 'could', 'would', 'should', 'you',
                    'please', 'pls', 'po', 'to', 'is', 'are', 'be', 'how', 'what', 'where', 'why', 'of', 'on',
                    'in', 'for', 'this', 'it', 'hi', 'hello', 'corpus', 'here'];
    return array_values(array_unique(array_diff($words, $skip)));
}

/**
 * The ready answer a typed question asks for, or null.
 *
 * Strict on purpose: a canned answer to a question that was not quite asked is
 * worse than a moment's wait for the AI. Every meaningful word of the question
 * must belong to one wording of an entry, and that wording must be mostly
 * covered, so "how do I search" matches and "how do I search for papers on
 * climate" (a real search, with words of its own) goes to the AI.
 */
function corpus_faq_match(string $message, array $faqs): ?array {
    $asked = corpus_faq_words($message);
    if (!$asked) return null;
    $best = null;
    $bestScore = 0.0;
    foreach ($faqs as $f) {
        foreach (array_merge([$f['q']], $f['also']) as $wording) {
            $w = corpus_faq_words($wording);
            if (!$w) continue;
            $shared = count(array_intersect($asked, $w));
            if ($shared < count($asked)) continue;   // a word of their own: not this question
            $score = $shared / count($w);
            if ($score >= 0.6 && $score > $bestScore) { $best = $f; $bestScore = $score; }
        }
    }
    return $best;
}
