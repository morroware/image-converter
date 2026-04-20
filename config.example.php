<?php
/**
 * The Castle Fun Center — Image Toolkit
 * Configuration file
 *
 * COPY THIS FILE TO config.php AND EDIT THE VALUES BELOW.
 *   cp config.example.php config.php
 *
 * All constants are optional — uncomment to override defaults.
 */

// ---------------------------------------------------------------------------
// UPLOAD LIMITS
// ---------------------------------------------------------------------------

// Max upload size per file, in bytes (default 25 MB).
define('CASTLE_MAX_FILE_SIZE', 25 * 1024 * 1024);

// Max image dimension in pixels (safety clamp).
define('CASTLE_MAX_DIMENSION', 8192);

// Default JPEG / WebP / AVIF quality (1-100).
define('CASTLE_DEFAULT_QUALITY', 82);

// ---------------------------------------------------------------------------
// PDF LIMITS
// ---------------------------------------------------------------------------

// Hard cap on pages when rasterizing a PDF (protects shared-host memory).
define('CASTLE_MAX_PDF_PAGES', 50);

// Default DPI for PDF → image extraction.
define('CASTLE_PDF_DEFAULT_DPI', 150);

// ---------------------------------------------------------------------------
// RATE LIMITING
// ---------------------------------------------------------------------------

// Requests per minute per IP to the conversion API. Set to 0 to disable.
define('CASTLE_RATE_LIMIT_PER_MIN', 60);

// ---------------------------------------------------------------------------
// AUTHENTICATION (OPTIONAL)
// ---------------------------------------------------------------------------
//
// Protect the whole app behind a password.
//   1) Set CASTLE_AUTH_ENABLED to true.
//   2) Generate a password hash at the command line:
//        php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
//   3) Paste the output into CASTLE_AUTH_HASH below.

define('CASTLE_AUTH_ENABLED', false);
define('CASTLE_AUTH_HASH', ''); // e.g. '$2y$10$....'

// Session cookie name (change if running multiple instances on one host).
define('CASTLE_SESSION_NAME', 'castlefc_img');

// ---------------------------------------------------------------------------
// PATHS
// ---------------------------------------------------------------------------

// Output directory (must be writable by PHP).
define('CASTLE_OUTPUT_DIR', __DIR__ . '/optimized');

// Public URL path to the output directory (relative to app root).
define('CASTLE_OUTPUT_URL', 'optimized');

// ---------------------------------------------------------------------------
// BRANDING
// ---------------------------------------------------------------------------

define('CASTLE_BRAND_NAME', 'The Castle Fun Center');
define('CASTLE_BRAND_TAGLINE', 'Image Toolkit');
