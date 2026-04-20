<?php
/**
 * The Castle Fun Center — Image Toolkit
 * Single JSON-over-HTTP router for all AJAX actions.
 *
 * Entry: /api.php?action=<name>
 *
 * Actions:
 *   GET  capabilities    — returns the FormatHandler report
 *   GET  list            — returns all files in CASTLE_OUTPUT_DIR
 *   POST convert         — multipart, single image → converted outputs
 *   POST fetch_url       — download an image URL server-side into the queue
 *   POST image_to_pdf    — multipart, N images → single PDF
 *   POST pdf_upload      — multipart PDF, returns token + preview thumbs
 *   POST pdf_to_image    — rasterize a previously-uploaded PDF
 *   POST delete          — {files:[...]}: unlink N files from output dir
 *   GET/POST zip         — stream a ZIP of the given filenames
 *   POST rename          — {from:..., to:...}: rename a file in output dir
 */

require_once __DIR__ . '/bootstrap.php';

use Castle\Response;
use Castle\Uploader;
use Castle\ImageOptimizer;
use Castle\PdfWriter;
use Castle\PdfRasterizer;
use Castle\RateLimiter;

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** @var \Castle\FormatHandler $formats */
$formats = $GLOBALS['castle_formats'];

// Rate-limit anything that writes.
$writeActions = ['convert', 'fetch_url', 'image_to_pdf', 'pdf_upload', 'pdf_to_image'];
if (in_array($action, $writeActions, true)) {
    $rl = new RateLimiter();
    if (!$rl->hit()) {
        Response::error('Rate limit exceeded — try again in a minute.', 429);
    }
}

try {
    switch ($action) {
        case 'capabilities':
            Response::json($formats->getCapabilityReport());

        case 'list':
            Response::json(['files' => listOutputFiles()]);

        case 'convert':
            Response::requireCsrf();
            handleConvert($formats);
            break;

        case 'fetch_url':
            Response::requireCsrf();
            handleFetchUrl($formats);
            break;

        case 'image_to_pdf':
            Response::requireCsrf();
            handleImageToPdf($formats);
            break;

        case 'pdf_upload':
            Response::requireCsrf();
            handlePdfUpload($formats);
            break;

        case 'pdf_to_image':
            Response::requireCsrf();
            handlePdfToImage($formats);
            break;

        case 'delete':
            Response::requireCsrf();
            handleDelete();
            break;

        case 'zip':
            // Accept either GET or POST; form submit from gallery.js uses POST.
            Response::requireCsrf();
            handleZip();
            break;

        case 'rename':
            Response::requireCsrf();
            handleRename();
            break;

        default:
            Response::error('Unknown action', 404);
    }
} catch (\Throwable $e) {
    Response::error($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

function jsonPayload(): array
{
    if (!empty($_POST)) return $_POST;
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function listOutputFiles(): array
{
    $dir = rtrim(CASTLE_OUTPUT_DIR, '/');
    if (!is_dir($dir)) return [];
    $out = [];
    $allowed = ['jpg','jpeg','png','gif','webp','avif','bmp','pdf','tiff','tif','ico','heic','webmanifest'];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.') continue;
        $full = $dir . '/' . $f;
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $info = @getimagesize($full);
        $out[] = [
            'filename'       => $f,
            'ext'            => $ext,
            'size'           => (int) filesize($full),
            'size_formatted' => Uploader::formatBytes((int) filesize($full)),
            'mtime'          => (int) filemtime($full),
            'width'          => $info[0] ?? 0,
            'height'         => $info[1] ?? 0,
            'url'            => rtrim(CASTLE_OUTPUT_URL, '/') . '/' . rawurlencode($f),
        ];
    }
    return $out;
}

function handleConvert(\Castle\FormatHandler $formats): void
{
    $optionsJson = $_POST['options'] ?? '{}';
    $options = json_decode($optionsJson, true);
    if (!is_array($options)) $options = [];

    $fileEntry = null;
    if (!empty($_FILES['file'])) {
        $fileEntry = Uploader::validate($_FILES['file'], $formats);
    } elseif (!empty($_POST['remote'])) {
        $fileEntry = resolveRemoteTmp((string)$_POST['remote'], $formats);
    } else {
        Response::error('No file in request.');
    }

    $opt = new ImageOptimizer($formats);
    $result = $opt->optimize($fileEntry, $options);
    Response::json($result, $result['success'] ? 200 : 400);
}

function handleFetchUrl(\Castle\FormatHandler $formats): void
{
    $data = jsonPayload();
    $url = (string)($data['url'] ?? '');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        Response::error('Invalid URL.');
    }

    // Basic SSRF guards — refuse private IP ranges.
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        Response::error('URL resolves to a private address.', 400);
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout'     => 15,
            'max_redirects' => 3,
            'header'      => "User-Agent: CastleImageToolkit/1.0\r\n",
        ],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $bytes = @file_get_contents($url, false, $ctx, 0, CASTLE_MAX_FILE_SIZE + 1);
    if ($bytes === false || $bytes === '') {
        Response::error('Could not download URL.');
    }
    if (strlen($bytes) > CASTLE_MAX_FILE_SIZE) {
        Response::error('Remote file exceeds size limit.');
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'castle_');
    file_put_contents($tmpPath, $bytes);

    $name = basename(parse_url($url, PHP_URL_PATH) ?: 'download');
    if (!pathinfo($name, PATHINFO_EXTENSION)) $name .= '.jpg';

    $ext = Uploader::canonExt(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $formats->getSupportedInputFormats(), true)) {
        @unlink($tmpPath);
        Response::error('Unsupported remote format: .' . $ext);
    }

    $token = bin2hex(random_bytes(8));
    $tokenPath = rtrim(CASTLE_OUTPUT_DIR, '/') . '/.tmp_' . $token;
    @rename($tmpPath, $tokenPath);

    Response::json(['file' => [
        'tmpToken' => $token,
        'name'     => Uploader::safeName($name) . '.' . $ext,
        'size'     => strlen($bytes),
        'ext'      => $ext,
        'preview'  => '',
    ]]);
}

function resolveRemoteTmp(string $token, \Castle\FormatHandler $formats): array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
        throw new \RuntimeException('Invalid remote token.');
    }
    $path = rtrim(CASTLE_OUTPUT_DIR, '/') . '/.tmp_' . $token;
    if (!is_file($path)) throw new \RuntimeException('Remote file expired.');

    // Rewrap to look like a validated upload. We bypass is_uploaded_file by
    // re-running only the extension/MIME checks inline.
    $size = (int) filesize($path);
    if ($size <= 0 || $size > CASTLE_MAX_FILE_SIZE) {
        throw new \RuntimeException('Remote file size invalid.');
    }
    $ext = Uploader::canonExt(strtolower(pathinfo($path, PATHINFO_EXTENSION))) ?: 'jpeg';
    if (!in_array($ext, $formats->getSupportedInputFormats(), true)) {
        throw new \RuntimeException('Unsupported remote format.');
    }
    return [
        'tmp'  => $path,
        'name' => basename($path),
        'size' => $size,
        'mime' => 'application/octet-stream',
        'ext'  => $ext,
    ];
}

function handleImageToPdf(\Castle\FormatHandler $formats): void
{
    $optionsJson = $_POST['options'] ?? '{}';
    $options = json_decode($optionsJson, true);
    if (!is_array($options)) $options = [];

    if (empty($_FILES['files'])) {
        Response::error('No images provided.');
    }

    // Normalize $_FILES['files'] to per-index array.
    $files = $_FILES['files'];
    $count = is_array($files['name']) ? count($files['name']) : 1;
    $entries = [];
    for ($i = 0; $i < $count; $i++) {
        $one = [
            'name'     => $files['name'][$i]     ?? '',
            'type'     => $files['type'][$i]     ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error'    => $files['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'][$i]     ?? 0,
        ];
        try {
            $entries[] = Uploader::validate($one, $formats);
        } catch (\Throwable $e) {
            Response::error('File ' . ($i + 1) . ': ' . $e->getMessage());
        }
    }

    // Build prepared input list for PdfWriter.
    $prepared = [];
    foreach ($entries as $e) {
        $prepared[] = ['path' => $e['tmp'], 'ext' => $e['ext']];
    }

    $title = (string)($options['title'] ?? 'document');
    $safeBase = Uploader::safeName($title);
    $filename = $safeBase . '-' . date('Ymd-His') . '.pdf';
    $dest     = rtrim(CASTLE_OUTPUT_DIR, '/') . '/' . $filename;

    // Imagick path when available (handles every input format one-shot).
    $ok = false;
    if ($formats->hasImagick() && preg_match('/^[A-Za-z0-9_.-]+$/', $filename)) {
        try {
            $im = new \Imagick();
            $im->setBackgroundColor(new \ImagickPixel('white'));
            foreach ($prepared as $p) {
                $page = new \Imagick();
                $page->setBackgroundColor(new \ImagickPixel('white'));
                $page->readImage($p['path']);
                $page = $page->flattenImages();
                $page->setImageFormat('pdf');
                $im->addImage($page);
            }
            $im->setImageFormat('pdf');
            $ok = $im->writeImages($dest, true);
            $im->clear();
        } catch (\Throwable $e) {
            // Fall through to pure-PHP writer.
            $ok = false;
        }
    }

    if (!$ok) {
        $writer = new PdfWriter();
        $ok = $writer->write($prepared, $options, $dest);
    }

    if (!$ok || !is_file($dest)) {
        Response::error('PDF generation failed.', 500);
    }

    $size = (int) filesize($dest);
    Response::json([
        'success'        => true,
        'filename'       => $filename,
        'size'           => $size,
        'size_formatted' => Uploader::formatBytes($size),
        'url'            => rtrim(CASTLE_OUTPUT_URL, '/') . '/' . rawurlencode($filename),
    ]);
}

function handlePdfUpload(\Castle\FormatHandler $formats): void
{
    if (!$formats->canRasterizePdf()) {
        Response::error('PDF rasterization is unavailable on this host.', 400);
    }
    if (empty($_FILES['file'])) {
        Response::error('No PDF provided.');
    }
    $file = Uploader::validate($_FILES['file'], $formats);
    if ($file['ext'] !== 'pdf') {
        Response::error('Not a PDF file.');
    }

    $token = bin2hex(random_bytes(8));
    $destPath = rtrim(CASTLE_OUTPUT_DIR, '/') . '/.pdf_' . $token . '.pdf';
    if (!move_uploaded_file($file['tmp'], $destPath)) {
        Response::error('Could not store PDF.', 500);
    }

    $rast = new PdfRasterizer($formats);
    $info = $rast->pageCount($destPath);
    $pages = (int) $info['pages'];

    if ($pages > CASTLE_MAX_PDF_PAGES) {
        @unlink($destPath);
        Response::error(sprintf('PDF has %d pages — over the %d page safety limit.',
            $pages, CASTLE_MAX_PDF_PAGES));
    }

    // Build data-URL thumbnails for small PDFs.
    $thumbs = [];
    if ($pages <= 10) {
        for ($p = 0; $p < $pages; $p++) {
            try {
                $blob = $rast->renderThumbnail($destPath, $p, 72);
                $thumbs[] = [
                    'page'    => $p + 1,
                    'dataUrl' => 'data:image/png;base64,' . base64_encode($blob),
                ];
            } catch (\Throwable $e) {
                // skip this thumbnail
            }
        }
    }

    Response::json([
        'token'      => $token,
        'pages'      => $pages,
        'thumbnails' => $thumbs,
    ]);
}

function handlePdfToImage(\Castle\FormatHandler $formats): void
{
    if (!$formats->canRasterizePdf()) {
        Response::error('PDF rasterization is unavailable on this host.', 400);
    }
    $data = jsonPayload();
    $token = (string)($data['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
        Response::error('Invalid token.');
    }
    $pdfPath = rtrim(CASTLE_OUTPUT_DIR, '/') . '/.pdf_' . $token . '.pdf';
    if (!is_file($pdfPath)) {
        Response::error('PDF session expired.');
    }

    $pages   = is_array($data['pages'] ?? null) ? $data['pages'] : [];
    $dpi     = (int)($data['dpi']     ?? CASTLE_PDF_DEFAULT_DPI);
    $format  = (string)($data['format'] ?? 'png');
    $quality = (int)($data['quality'] ?? 90);

    $rast = new PdfRasterizer($formats);
    $results = $rast->rasterize($pdfPath, 'pdf-' . $token, $pages, $dpi, $format, $quality);

    Response::json(['success' => true, 'results' => $results]);
}

function handleDelete(): void
{
    $data  = jsonPayload();
    $files = is_array($data['files'] ?? null) ? $data['files'] : [];
    if (!$files) Response::error('No files specified.');

    $deleted = 0;
    $dir = rtrim(CASTLE_OUTPUT_DIR, '/');
    foreach ($files as $name) {
        $safe = basename((string)$name); // strip path traversal
        if ($safe === '' || $safe[0] === '.') continue;
        $path = $dir . '/' . $safe;
        if (is_file($path) && @unlink($path)) $deleted++;
    }
    Response::json(['deleted' => $deleted]);
}

function handleZip(): void
{
    if (!class_exists('ZipArchive')) {
        Response::error('ZIP support unavailable on this host.', 500);
    }
    $raw = $_POST['files'] ?? ($_GET['files'] ?? '[]');
    $files = json_decode((string)$raw, true);
    if (!is_array($files) || !$files) {
        Response::error('No files specified.');
    }

    $dir = rtrim(CASTLE_OUTPUT_DIR, '/');
    $zipPath = tempnam(sys_get_temp_dir(), 'castle_zip_');
    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        Response::error('Could not create ZIP.', 500);
    }

    foreach ($files as $name) {
        $safe = basename((string)$name);
        if ($safe === '' || $safe[0] === '.') continue;
        $src = $dir . '/' . $safe;
        if (is_file($src)) $zip->addFile($src, $safe);
    }
    $zip->close();

    if (!is_file($zipPath) || filesize($zipPath) === 0) {
        @unlink($zipPath);
        Response::error('ZIP is empty.', 500);
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="castle-images-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

function handleRename(): void
{
    $data = jsonPayload();
    $from = basename((string)($data['from'] ?? ''));
    $to   = basename((string)($data['to']   ?? ''));
    if ($from === '' || $to === '' || $from[0] === '.' || $to[0] === '.') {
        Response::error('Invalid names.');
    }
    // Keep extension fixed.
    $fromExt = pathinfo($from, PATHINFO_EXTENSION);
    $toExt   = pathinfo($to, PATHINFO_EXTENSION);
    if (strtolower($fromExt) !== strtolower($toExt)) {
        $to .= '.' . $fromExt;
    }
    $to = Uploader::safeName($to) . '.' . $fromExt;

    $dir = rtrim(CASTLE_OUTPUT_DIR, '/');
    $src = $dir . '/' . $from;
    $dst = $dir . '/' . $to;
    if (!is_file($src)) Response::error('Original not found.');
    if (is_file($dst))  Response::error('Destination already exists.');
    if (!@rename($src, $dst)) Response::error('Rename failed.', 500);
    Response::json(['success' => true, 'filename' => $to]);
}
