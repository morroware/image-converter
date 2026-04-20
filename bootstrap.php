<?php
/**
 * Castle Image Toolkit — bootstrap
 * Loaded by every entry point (index.php, gallery.php, api.php, login.php).
 *
 * Order of operations:
 *   1. Load config.php (or config.example.php fallback).
 *   2. Apply sane PHP defaults.
 *   3. Start a hardened session.
 *   4. Autoload /lib classes.
 *   5. Initialise the FormatHandler singleton.
 *   6. Enforce auth if enabled (except on the login page itself).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Config
// ---------------------------------------------------------------------------

$configPath  = __DIR__ . '/config.php';
$examplePath = __DIR__ . '/config.example.php';

if (is_file($configPath)) {
    require_once $configPath;
} elseif (is_file($examplePath)) {
    require_once $examplePath;
} else {
    http_response_code(500);
    exit('Missing config.php — copy config.example.php to config.php.');
}

// Fill in any undefined constants with safe defaults (lets users ship a
// minimal config.php with only the settings they care about).
$defaults = [
    'CASTLE_MAX_FILE_SIZE'     => 25 * 1024 * 1024,
    'CASTLE_MAX_DIMENSION'     => 8192,
    'CASTLE_DEFAULT_QUALITY'   => 82,
    'CASTLE_MAX_PDF_PAGES'     => 50,
    'CASTLE_PDF_DEFAULT_DPI'   => 150,
    'CASTLE_RATE_LIMIT_PER_MIN'=> 60,
    'CASTLE_AUTH_ENABLED'      => false,
    'CASTLE_AUTH_HASH'         => '',
    'CASTLE_SESSION_NAME'      => 'castlefc_img',
    'CASTLE_OUTPUT_DIR'        => __DIR__ . '/optimized',
    'CASTLE_OUTPUT_URL'        => 'optimized',
    'CASTLE_BRAND_NAME'        => 'The Castle Fun Center',
    'CASTLE_BRAND_TAGLINE'     => 'Image Toolkit',
];
foreach ($defaults as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}

// ---------------------------------------------------------------------------
// 2. PHP runtime
// ---------------------------------------------------------------------------

date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');
mb_internal_encoding('UTF-8');

// Ensure the output directory exists and is writable.
if (!is_dir(CASTLE_OUTPUT_DIR)) {
    @mkdir(CASTLE_OUTPUT_DIR, 0775, true);
}

// ---------------------------------------------------------------------------
// 3. Session
// ---------------------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_name(CASTLE_SESSION_NAME);
    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookieParams);
    session_start();
}

// CSRF token bootstrap — one per session.
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ---------------------------------------------------------------------------
// 4. Autoload /lib
// ---------------------------------------------------------------------------

spl_autoload_register(function (string $class): void {
    // Only handle our own classes.
    $allowed = [
        'Castle\\Response'       => __DIR__ . '/lib/Response.php',
        'Castle\\Auth'           => __DIR__ . '/lib/Auth.php',
        'Castle\\RateLimiter'    => __DIR__ . '/lib/RateLimiter.php',
        'Castle\\Uploader'       => __DIR__ . '/lib/Uploader.php',
        'Castle\\FormatHandler'  => __DIR__ . '/lib/FormatHandler.php',
        'Castle\\ImageOptimizer' => __DIR__ . '/lib/ImageOptimizer.php',
        'Castle\\PdfWriter'      => __DIR__ . '/lib/PdfWriter.php',
        'Castle\\PdfRasterizer'  => __DIR__ . '/lib/PdfRasterizer.php',
    ];
    if (isset($allowed[$class]) && is_file($allowed[$class])) {
        require_once $allowed[$class];
    }
});

// FPDF is not a namespaced class — lazy-load when needed.
function castle_require_fpdf(): void {
    if (!class_exists('FPDF')) {
        require_once __DIR__ . '/lib/fpdf.php';
    }
}

// ---------------------------------------------------------------------------
// 5. FormatHandler singleton
// ---------------------------------------------------------------------------

$GLOBALS['castle_formats'] = \Castle\FormatHandler::getInstance();

// ---------------------------------------------------------------------------
// 6. Auth gate
// ---------------------------------------------------------------------------

$scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
$publicPages = ['login.php', 'logout.php'];

if (CASTLE_AUTH_ENABLED && !in_array($scriptName, $publicPages, true)) {
    if (!\Castle\Auth::isAuthenticated()) {
        // For AJAX, return JSON 401; for page loads, redirect to login.
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            \Castle\Response::json(['error' => 'Authentication required'], 401);
        }
        header('Location: login.php');
        exit;
    }
}
