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
    $programMap = [
        'Bachelor of Science in Information Technology' => 'BSIT',
        'Bachelor of Science in Industrial Engineering' => 'BSIE',
        'Bachelor of Science in Computer Engineering' => 'BSCPE',
        'Bachelor of Secondary Education major in English' => 'BSED-ENG',
        'Bachelor of Secondary Education major in Social Studies' => 'BSED-SS',
        'Bachelor of Elementary Education' => 'BEED',
        'Bachelor of Science in Psychology' => 'BSPSYCH',
        'Diploma in Information Technology' => 'DIT',
        'Diploma in Computer Engineering Technology' => 'DCPET',
        'Bachelor of Science in Business Administration major in Human Resource Management' => 'BSBA-HRM',
        'Faculty Member' => 'FACULTY'
    ];

    return safe_name($programMap[$program] ?? 'Other');
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

function validate_pdf_upload(array $file, int $maxBytes = 52428800): void {
    if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Research PDF is required and must be uploaded successfully.');
    }

    if ($file['size'] <= 0 || $file['size']> $maxBytes) {
        throw new Exception('PDF too large (max 50MB)');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($file['tmp_name']) !== 'application/pdf') {
        throw new Exception('Only PDF files allowed');
    }
}
