<?php
/**
 * Global Error & Exception Handler
 * 
 * Prevents Application Error Disclosure (OWASP ZAP ID-001) by:
 * 1. Generating a unique error reference ID per incident
 * 2. Logging full technical details server-side
 * 3. Showing users only a generic message + reference ID
 */

/**
 * Generate a short, unique error reference ID
 */
function generate_error_ref(): string {
    return 'ERR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/**
 * Log error details to server error log with reference ID
 */
function log_error_detail(string $ref, string $type, string $message, string $file = '', int $line = 0, string $trace = ''): void {
    $logEntry = sprintf(
        "[%s] %s | Type: %s | Message: %s | File: %s | Line: %d",
        $ref,
        date('Y-m-d H:i:s'),
        $type,
        $message,
        $file,
        $line
    );
    if ($trace) {
        $logEntry .= " | Trace: " . $trace;
    }
    error_log($logEntry);
}

/**
 * Show a generic error response (HTML or JSON based on request type)
 */
function show_safe_error(string $ref, int $httpCode = 500): void {
    // Don't send headers if they've already been sent
    if (!headers_sent()) {
        http_response_code($httpCode);
    }

    // Detect if this is an AJAX/JSON request
    $isAjax = (
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false && isset($_POST['action']))
    );

    if ($isAjax) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error' => 'An unexpected error occurred. Please try again.',
            'message' => 'An unexpected error occurred. Please try again.',
            'reference' => $ref
        ]);
    } else {
        // Try to include the styled error page
        $errorPage = __DIR__ . '/../pages/error_page.php';
        if (file_exists($errorPage)) {
            // Make ref available to the error page
            $error_reference = $ref;
            $error_http_code = $httpCode;
            include $errorPage;
        } else {
            // Minimal fallback
            echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
            echo '<h1>Something went wrong</h1>';
            echo '<p>An unexpected error occurred. Please try again later.</p>';
            echo '<p><strong>Reference:</strong> ' . htmlspecialchars($ref) . '</p>';
            echo '</body></html>';
        }
    }
    exit;
}

/**
 * Custom error handler for PHP errors (warnings, notices, etc.)
 */
function custom_error_handler(int $errno, string $errstr, string $errfile, int $errline): bool {
    // Don't handle errors that are suppressed with @
    if (!(error_reporting() & $errno)) {
        return false;
    }

    $ref = generate_error_ref();
    $type = match($errno) {
        E_ERROR, E_USER_ERROR => 'Fatal Error',
        E_WARNING, E_USER_WARNING => 'Warning',
        E_NOTICE, E_USER_NOTICE => 'Notice',
        E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
        default => 'Error'
    };

    log_error_detail($ref, $type, $errstr, $errfile, $errline);

    // Only show error page for fatal-level errors
    // Warnings and notices are logged silently
    if (in_array($errno, [E_ERROR, E_USER_ERROR])) {
        show_safe_error($ref);
    }

    // Return true to prevent PHP's default error handler
    return true;
}

/**
 * Custom exception handler for uncaught exceptions
 */
function custom_exception_handler(\Throwable $exception): void {
    $ref = generate_error_ref();
    log_error_detail(
        $ref,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    show_safe_error($ref);
}

/**
 * Shutdown handler — catches fatal errors that bypass set_error_handler
 */
function custom_shutdown_handler(): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        $ref = generate_error_ref();
        log_error_detail($ref, 'Fatal Error (Shutdown)', $error['message'], $error['file'], $error['line']);
        show_safe_error($ref);
    }
}

// Register all handlers
set_error_handler('custom_error_handler');
set_exception_handler('custom_exception_handler');
register_shutdown_function('custom_shutdown_handler');

/**
 * Helper: Create a safe error message for JSON API responses.
 * Logs the real error and returns a sanitized user-facing message.
 * 
 * Usage in catch blocks:
 *   $safeMsg = safe_error_message($e, 'Upload failed');
 *   echo json_encode(['success' => false, 'message' => $safeMsg['message'], 'reference' => $safeMsg['reference']]);
 */
function safe_error_message(\Throwable $e, string $userMessage = 'An unexpected error occurred. Please try again.'): array {
    $ref = generate_error_ref();
    log_error_detail(
        $ref,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    return [
        'message' => $userMessage,
        'reference' => $ref
    ];
}
