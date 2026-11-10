<?php

function app_root_path(): string {
    static $root = null;
    if ($root === null) {
        $root = realpath(__DIR__ . '/../../');
        if ($root === false) {
            $root = __DIR__ . '/../../';
        }
    }
    return rtrim($root, '/\\');
}

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function safe_name(string $name): string {
    return preg_replace('/[^A-Za-z0-9_\-.]+/', '_', trim($name, '._'));
}

function get_program_folder(string $program): string {
    /* programs_map() in config/core.php is the list, and this used to keep a
       second copy of it. The two drifted the moment a programme was added:
       Accountancy became a real programme everywhere on the site while its
       papers went on being filed in uploads/research/Other/. Faculty Member is
       not a programme and so is not in that map, which is why it is named here.
       Anything else still falls back to Other rather than inventing a folder. */
    if ($program === 'Faculty Member') { return 'FACULTY'; }

    $code = function_exists('programs_map') ? (programs_map()[$program] ?? null) : null;

    return safe_name($code ?? 'Other');
}

function build_research_upload_directory(string $programFolder, string $paperType, string $year, string $month, string $submissionFolder): string {
    return app_root_path() . "/uploads/research/{$programFolder}/{$paperType}/{$year}/{$month}/{$submissionFolder}";
}

function build_submission_folder(string $title): string {
    $safeTitle = safe_name($title);
    if (strlen($safeTitle)> 30) {
        $safeTitle = substr($safeTitle, 0, 30);
    }
    return uniqid('paper_', true) . "_{$safeTitle}";
}

function validate_pdf_upload(array $file, ?int $maxBytes = null): void {
    // Null means "whatever this server actually allows", which is the only
    // answer that stays right when the host changes.
    $maxBytes = $maxBytes ?? max_upload_bytes();

    if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Research PDF is required and must be uploaded successfully.');
    }

    if ($file['size'] <= 0 || $file['size']> $maxBytes) {
        throw new Exception('PDF too large (max ' . max_upload_label() . ')');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($file['tmp_name']) !== 'application/pdf') {
        throw new Exception('Only PDF files allowed');
    }
}
