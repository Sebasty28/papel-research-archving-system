<?php
// ai_extract.php - AI Metadata Extraction Endpoint
require_once __DIR__.'/../config/core.php';
require_once __DIR__.'/../config/groq_config.php';
require_role(['student']);

header('Content-Type: application/json');

// Check if file was uploaded
if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'No PDF file uploaded']));
}

$pdfFile = $_FILES['pdf_file'];

// Validate file type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($pdfFile['tmp_name']);
if ($mimeType !== 'application/pdf') {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'File must be a PDF']));
}

// Validate file size (max 50MB)
if ($pdfFile['size']> 50 * 1024 * 1024) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'PDF file too large (max 50MB)']));
}

try {
    // Extract text from PDF
    $pdfText = extract_pdf_text($pdfFile['tmp_name']);
    
    if (!$pdfText || strlen($pdfText) < 100) {
        throw new Exception('Could not extract text from PDF. The file may be scanned or protected.');
    }

    // Extract metadata using AI
    $metadata = extract_metadata_with_groq($pdfText);
    
    if (!$metadata) {
        throw new Exception('AI extraction failed. Please try again or enter metadata manually.');
    }

    // Generate statistical analysis
    $analysis = function_exists('generate_statistical_analysis') ? generate_statistical_analysis($pdfText) : [];

    // Prepare response
    $response = [
        'success' => true,
        'metadata' => [
            'title' => $metadata['title'] ?? 'Untitled',
            'authors' => $metadata['authors'] ?? '',
            'year' => $metadata['year'] ?? date('Y'),
            'keywords' => $metadata['keywords'] ?? '',
            'abstract' => $metadata['abstract'] ?? ''
        ],
        'analysis' => [
            'summary' => $analysis['summary'] ?? null,
            'methodology' => $analysis['methodology'] ?? null,
            'sample_size' => $analysis['sample_size'] ?? null,
            'statistical_methods' => $analysis['statistical_methods'] ?? null,
            'variables' => $analysis['variables'] ?? null,
            'research_field' => $analysis['research_field'] ?? null
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}