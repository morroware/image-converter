<?php
/**
 * Professional Image Optimizer for Web Developers
 * Advanced multi-format converter with resizing, batch operations, and code generation
 * 
 * @version 4.0
 */

// Configuration
define('MAX_FILE_SIZE', 10485760);
define('UPLOAD_DIR', __DIR__ . '/optimized/');
define('DEFAULT_QUALITY', 80);
define('MAX_DIMENSION', 4096);

if (!file_exists(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}

class ImageOptimizer {
    private $errors = [];
    private $warnings = [];
    
    public static function checkSupport(): array {
        $status = [
            'gd_loaded' => extension_loaded('gd'),
            'webp_support' => false,
            'avif_support' => false,
            'version' => 'N/A'
        ];
        
        if ($status['gd_loaded']) {
            $gdInfo = gd_info();
            $status['version'] = $gdInfo['GD Version'] ?? 'Unknown';
            $status['webp_support'] = !empty($gdInfo['WebP Support']);
            $status['avif_support'] = function_exists('imageavif');
        }
        
        return $status;
    }
    
    private function validateUpload(array $fileData): bool {
        if ($fileData['error'] !== UPLOAD_ERR_OK) {
            $this->errors[] = "Upload error code: {$fileData['error']}";
            return false;
        }
        
        if ($fileData['size'] > MAX_FILE_SIZE || $fileData['size'] === 0) {
            $this->errors[] = "Invalid file size";
            return false;
        }
        
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($fileData['tmp_name']);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (!in_array($mimeType, $allowedMimes)) {
                $this->errors[] = "Invalid file type: {$mimeType}";
                return false;
            }
        }
        
        $imageInfo = @getimagesize($fileData['tmp_name']);
        if ($imageInfo === false) {
            $this->errors[] = "Not a valid image";
            return false;
        }
        
        return true;
    }
    
    private function stripExif(string $sourcePath, string $destPath): bool {
        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) return false;
        
        $mime = $imageInfo['mime'];
        $img = false;
        
        switch ($mime) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $img = @imagecreatefrompng($sourcePath);
                break;
            case 'image/webp':
                $img = @imagecreatefromwebp($sourcePath);
                break;
            default:
                return false;
        }
        
        if ($img === false) return false;
        
        $success = false;
        switch ($mime) {
            case 'image/jpeg':
                $success = @imagejpeg($img, $destPath, 100);
                break;
            case 'image/png':
                imagealphablending($img, false);
                imagesavealpha($img, true);
                $success = @imagepng($img, $destPath, 0);
                break;
            case 'image/webp':
                $success = @imagewebp($img, $destPath, 100);
                break;
        }
        
        imagedestroy($img);
        return $success;
    }
    
    private function generateFilename(string $tmpPath, string $originalName, string $format, array $options): string {
        $hash = substr(sha1_file($tmpPath), 0, 8);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeName = substr($safeName, 0, 40);
        
        $pattern = $options['naming_pattern'] ?? '{name}';
        $filename = str_replace(
            ['{name}', '{hash}', '{width}', '{height}', '{format}'],
            [$safeName, $hash, $options['target_width'] ?? 'orig', $options['target_height'] ?? 'orig', $format],
            $pattern
        );
        
        $sizeSuffix = $options['size_suffix'] ?? '';
        
        return $filename . $sizeSuffix . '.' . $format;
    }
    
    public function optimize(array $fileData, array $options): array {
        $this->errors = [];
        $this->warnings = [];
        
        if (!is_uploaded_file($fileData['tmp_name'])) {
            $this->errors[] = "Security error: Invalid upload";
            return ['success' => false, 'errors' => $this->errors];
        }
        
        if (!$this->validateUpload($fileData)) {
            return ['success' => false, 'errors' => $this->errors];
        }
        
        $sourceInfo = getimagesize($fileData['tmp_name']);
        $originalWidth = $sourceInfo[0];
        $originalHeight = $sourceInfo[1];
        $originalSize = $fileData['size'];
        
        $exifSize = 0;
        $hasExif = false;
        if (function_exists('exif_read_data') && $sourceInfo['mime'] === 'image/jpeg') {
            $exifData = @exif_read_data($fileData['tmp_name']);
            if ($exifData && count($exifData) > 0) {
                $hasExif = true;
            }
        }
        
        $tempPath = UPLOAD_DIR . 'temp_' . uniqid() . '.tmp';
        if (!move_uploaded_file($fileData['tmp_name'], $tempPath)) {
            $this->errors[] = "Failed to process upload";
            return ['success' => false, 'errors' => $this->errors];
        }
        
        $processPath = $tempPath;
        if (!empty($options['strip_exif']) && $hasExif) {
            $strippedPath = UPLOAD_DIR . 'stripped_' . uniqid() . '.tmp';
            if ($this->stripExif($tempPath, $strippedPath)) {
                $exifSize = filesize($tempPath) - filesize($strippedPath);
                @unlink($tempPath);
                $processPath = $strippedPath;
            }
        }
        
        $results = [];
        $formats = $options['formats'] ?? ['webp'];
        $quality = max(0, min(100, (int)($options['quality'] ?? DEFAULT_QUALITY)));
        
        $sizes = $this->determineSizes($originalWidth, $originalHeight, $options);
        
        foreach ($sizes as $sizeConfig) {
            $dimensions = [
                'width' => $sizeConfig['width'],
                'height' => $sizeConfig['height']
            ];
            
            $sizeOptions = $options;
            $sizeOptions['target_width'] = $dimensions['width'];
            $sizeOptions['target_height'] = $dimensions['height'];
            $sizeOptions['size_suffix'] = $sizeConfig['suffix'];
            
            foreach ($formats as $format) {
                $filename = $this->generateFilename($processPath, $fileData['name'], $format, $sizeOptions);
                $destPath = UPLOAD_DIR . $filename;
                
                $converted = $this->convertImage($processPath, $destPath, $format, $quality, $dimensions);
                
                if ($converted) {
                    $newSize = filesize($destPath);
                    $results[] = [
                        'format' => $format,
                        'filename' => $filename,
                        'size' => $newSize,
                        'size_formatted' => $this->formatBytes($newSize),
                        'reduction' => (($originalSize - $newSize) / $originalSize) * 100,
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                        'descriptor' => $sizeConfig['descriptor'],
                        'url' => 'optimized/' . $filename
                    ];
                }
            }
        }
        
        @unlink($processPath);
        
        if (empty($results)) {
            return ['success' => false, 'errors' => $this->errors];
        }
        
        return [
            'success' => true,
            'original' => [
                'name' => $fileData['name'],
                'size' => $originalSize,
                'size_formatted' => $this->formatBytes($originalSize),
                'width' => $originalWidth,
                'height' => $originalHeight,
                'mime' => $sourceInfo['mime'],
                'has_exif' => $hasExif,
                'exif_size' => $exifSize
            ],
            'results' => $results,
            'warnings' => $this->warnings
        ];
    }
    
    private function determineSizes(int $origWidth, int $origHeight, array $options): array {
        $sizes = [];
        
        if (!empty($options['responsive_sizes'])) {
            $preset = $options['responsive_preset'] ?? 'standard';
            
            $presets = [
                'standard' => [400, 800, 1200, 1600],
                'thumbnail' => [150, 300, 600],
                'social' => [640, 1080, 1920],
                'hero' => [800, 1200, 1920, 2560]
            ];
            
            $widths = $presets[$preset] ?? $presets['standard'];
            
            foreach ($widths as $width) {
                if ($width <= $origWidth) {
                    $ratio = $width / $origWidth;
                    $height = (int)($origHeight * $ratio);
                    $sizes[] = [
                        'width' => $width,
                        'height' => $height,
                        'suffix' => '-' . $width . 'w',
                        'descriptor' => $width . 'w'
                    ];
                }
            }
            
            if (empty($sizes) || end($sizes)['width'] < $origWidth) {
                $sizes[] = [
                    'width' => $origWidth,
                    'height' => $origHeight,
                    'suffix' => '',
                    'descriptor' => $origWidth . 'w'
                ];
            }
        } else {
            $dimensions = $this->calculateDimensions($origWidth, $origHeight, $options);
            $sizes[] = [
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'suffix' => '',
                'descriptor' => $dimensions['width'] . 'w'
            ];
        }
        
        return $sizes;
    }
    
    private function calculateDimensions(int $origWidth, int $origHeight, array $options): array {
        $width = $origWidth;
        $height = $origHeight;
        
        if (!empty($options['resize_mode'])) {
            $maxWidth = min((int)($options['max_width'] ?? MAX_DIMENSION), MAX_DIMENSION);
            $maxHeight = min((int)($options['max_height'] ?? MAX_DIMENSION), MAX_DIMENSION);
            
            switch ($options['resize_mode']) {
                case 'fit':
                    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
                    if ($ratio < 1) {
                        $width = (int)($origWidth * $ratio);
                        $height = (int)($origHeight * $ratio);
                    }
                    break;
                case 'width':
                    $width = $maxWidth;
                    $height = (int)($origHeight * ($maxWidth / $origWidth));
                    break;
                case 'height':
                    $height = $maxHeight;
                    $width = (int)($origWidth * ($maxHeight / $origHeight));
                    break;
                case 'exact':
                    $width = $maxWidth;
                    $height = $maxHeight;
                    $this->warnings[] = "Image may be distorted due to exact sizing";
                    break;
            }
        }
        
        return ['width' => $width, 'height' => $height];
    }
    
    private function convertImage(string $source, string $dest, string $format, int $quality, array $dimensions): bool {
        $imageInfo = @getimagesize($source);
        if ($imageInfo === false) {
            $this->errors[] = "Cannot read image";
            return false;
        }
        
        $mime = $imageInfo['mime'];
        $sourceImg = false;
        $hasAlpha = false;
        
        switch ($mime) {
            case 'image/jpeg':
                $sourceImg = @imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $sourceImg = @imagecreatefrompng($source);
                $hasAlpha = true;
                break;
            case 'image/gif':
                $sourceImg = @imagecreatefromgif($source);
                $hasAlpha = true;
                break;
            case 'image/webp':
                $sourceImg = @imagecreatefromwebp($source);
                $hasAlpha = true;
                break;
        }
        
        if ($sourceImg === false) {
            $this->errors[] = "Failed to load image for $format conversion";
            return false;
        }
        
        $origWidth = imagesx($sourceImg);
        $origHeight = imagesy($sourceImg);
        
        if ($dimensions['width'] !== $origWidth || $dimensions['height'] !== $origHeight) {
            $resized = imagecreatetruecolor($dimensions['width'], $dimensions['height']);
            
            if ($hasAlpha) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $transparent);
            }
            
            imagecopyresampled(
                $resized, $sourceImg,
                0, 0, 0, 0,
                $dimensions['width'], $dimensions['height'],
                $origWidth, $origHeight
            );
            
            imagedestroy($sourceImg);
            $sourceImg = $resized;
        }
        
        if ($hasAlpha) {
            imagepalettetotruecolor($sourceImg);
            imagealphablending($sourceImg, false);
            imagesavealpha($sourceImg, true);
        }
        
        $success = false;
        switch ($format) {
            case 'webp':
                $success = @imagewebp($sourceImg, $dest, $quality);
                break;
            case 'avif':
                if (function_exists('imageavif')) {
                    $success = @imageavif($sourceImg, $dest, $quality);
                } else {
                    $this->errors[] = "AVIF not supported";
                }
                break;
            case 'jpeg':
            case 'jpg':
                $success = @imagejpeg($sourceImg, $dest, $quality);
                break;
            case 'png':
                $pngQuality = (int)(9 - ($quality / 100 * 9));
                $success = @imagepng($sourceImg, $dest, $pngQuality);
                break;
        }
        
        imagedestroy($sourceImg);
        
        if (!$success) {
            $this->errors[] = "Failed to save $format image";
        }
        
        return $success;
    }
    
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// ZIP download handler
if (isset($_GET['download_all']) && isset($_GET['files'])) {
    $files = json_decode($_GET['files'], true);
    if (!is_array($files) || empty($files)) die('No files');
    
    $zipFilename = 'optimized_' . date('Ymd_His') . '.zip';
    $zipPath = UPLOAD_DIR . $zipFilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach ($files as $filename) {
            $safeName = basename($filename);
            $filePath = UPLOAD_DIR . $safeName;
            if (file_exists($filePath)) {
                $zip->addFile($filePath, $safeName);
            }
        }
        $zip->close();
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
    }
    exit;
}

// AJAX handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['image_file'])) {
        echo json_encode(['success' => false, 'errors' => ['No file uploaded']]);
        exit;
    }
    
    $options = [
        'quality' => $_POST['quality'] ?? DEFAULT_QUALITY,
        'formats' => json_decode($_POST['formats'] ?? '["webp"]', true),
        'resize_mode' => $_POST['resize_mode'] ?? null,
        'max_width' => $_POST['max_width'] ?? null,
        'max_height' => $_POST['max_height'] ?? null,
        'naming_pattern' => $_POST['naming_pattern'] ?? '{name}',
        'strip_exif' => isset($_POST['strip_exif']) && $_POST['strip_exif'] === '1',
        'responsive_sizes' => isset($_POST['responsive_sizes']) && $_POST['responsive_sizes'] === '1',
        'responsive_preset' => $_POST['responsive_preset'] ?? 'standard'
    ];
    
    $optimizer = new ImageOptimizer();
    $result = $optimizer->optimize($_FILES['image_file'], $options);
    
    echo json_encode($result);
    exit;
}

$serverStatus = ImageOptimizer::checkSupport();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optix — Image Optimizer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #09090b;
            --bg-elevated: #18181b;
            --bg-surface: #27272a;
            --bg-hover: #3f3f46;
            --border: #27272a;
            --border-hover: #3f3f46;
            --text-primary: #fafafa;
            --text-secondary: #a1a1aa;
            --text-muted: #71717a;
            --accent: #22c55e;
            --accent-hover: #16a34a;
            --accent-muted: rgba(34, 197, 94, 0.1);
            --danger: #ef4444;
            --danger-muted: rgba(239, 68, 68, 0.1);
            --warning: #f59e0b;
            --info: #3b82f6;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --font-sans: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-sans);
            background: var(--bg-base);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .app { max-width: 1320px; margin: 0 auto; padding: 32px 24px; }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .logo { display: flex; align-items: center; gap: 12px; }

        .logo-icon {
            width: 40px; height: 40px;
            background: var(--accent);
            border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
        }

        .logo h1 { font-size: 22px; font-weight: 600; letter-spacing: -0.5px; }
        .logo span { font-size: 12px; color: var(--text-muted); font-weight: 400; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px;
            font-family: var(--font-sans); font-size: 14px; font-weight: 500;
            border-radius: var(--radius-md);
            border: none; cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background: var(--accent); color: #000; }
        .btn-primary:hover:not(:disabled) { background: var(--accent-hover); }
        .btn-secondary { background: var(--bg-elevated); color: var(--text-primary); border: 1px solid var(--border); }
        .btn-secondary:hover:not(:disabled) { background: var(--bg-surface); border-color: var(--border-hover); }
        .btn-ghost { background: transparent; color: var(--text-secondary); }
        .btn-ghost:hover { background: var(--bg-elevated); color: var(--text-primary); }
        .btn-danger { background: var(--danger-muted); color: var(--danger); }
        .btn-danger:hover { background: var(--danger); color: white; }
        .btn-lg { padding: 14px 28px; font-size: 15px; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        /* Status Bar */
        .status-bar { display: flex; gap: 24px; margin-bottom: 32px; flex-wrap: wrap; }
        .status-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; }
        .status-dot.active { background: var(--accent); }
        .status-dot.inactive { background: var(--danger); }
        .status-dot.warning { background: var(--warning); }

        /* Workflow Steps */
        .workflow {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            margin-bottom: 40px; padding: 20px;
            background: var(--bg-elevated); border: 1px solid var(--border); border-radius: var(--radius-lg);
        }

        .workflow-step {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 20px; border-radius: var(--radius-md);
            font-size: 14px; font-weight: 500;
            color: var(--text-muted);
            transition: all 0.2s ease;
        }

        .workflow-step.active { background: var(--accent-muted); color: var(--accent); }
        .workflow-step.completed { color: var(--accent); }

        .workflow-step-number {
            width: 26px; height: 26px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; background: var(--bg-surface);
            font-size: 13px; font-weight: 600;
        }

        .workflow-step.active .workflow-step-number,
        .workflow-step.completed .workflow-step-number { background: var(--accent); color: #000; }

        .workflow-divider { width: 40px; height: 2px; background: var(--border); }

        /* Main Content */
        .main-content { display: none; }
        .main-content.active { display: block; animation: fadeIn 0.3s ease; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        /* Step 1: Upload */
        .upload-section { max-width: 700px; margin: 0 auto; }

        .drop-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius-lg);
            padding: 80px 32px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            background: linear-gradient(180deg, transparent, rgba(34, 197, 94, 0.02));
        }

        .drop-zone:hover, .drop-zone.drag-over { border-color: var(--accent); background: var(--accent-muted); }

        .drop-zone-icon {
            width: 80px; height: 80px; margin: 0 auto 24px;
            border-radius: var(--radius-lg); background: var(--bg-surface);
            display: flex; align-items: center; justify-content: center;
            font-size: 36px;
            transition: transform 0.25s ease;
        }

        .drop-zone:hover .drop-zone-icon { transform: translateY(-4px); }
        .drop-zone h3 { font-size: 18px; font-weight: 500; margin-bottom: 8px; }
        .drop-zone p { font-size: 14px; color: var(--text-muted); }
        .drop-zone p span { color: var(--accent); font-weight: 500; }

        /* Step 2: Queue & Settings */
        .queue-settings-layout { display: grid; grid-template-columns: 1fr 400px; gap: 24px; }
        @media (max-width: 1024px) { .queue-settings-layout { grid-template-columns: 1fr; } }

        /* Cards */
        .card { background: var(--bg-elevated); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
        .card-header { padding: 18px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); }
        .card-actions { display: flex; gap: 8px; }
        .card-body { padding: 24px; }

        /* Queue List */
        .queue-list { max-height: 500px; overflow-y: auto; }

        .queue-item {
            display: flex; align-items: center; gap: 16px;
            padding: 16px;
            border-bottom: 1px solid var(--border);
            transition: background 0.15s ease;
        }

        .queue-item:last-child { border-bottom: none; }
        .queue-item:hover { background: rgba(255,255,255,0.02); }

        .queue-thumb { width: 56px; height: 56px; border-radius: var(--radius-md); object-fit: cover; background: var(--bg-surface); flex-shrink: 0; }
        .queue-info { flex: 1; min-width: 0; }
        .queue-name { font-size: 14px; font-weight: 500; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .queue-meta { display: flex; gap: 12px; font-size: 12px; color: var(--text-muted); }
        .queue-status { display: flex; align-items: center; gap: 8px; }

        .queue-status-badge { padding: 4px 10px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 500; }
        .queue-status-badge.pending { background: var(--bg-surface); color: var(--text-secondary); }
        .queue-status-badge.processing { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .queue-status-badge.done { background: var(--accent-muted); color: var(--accent); }
        .queue-status-badge.error { background: var(--danger-muted); color: var(--danger); }

        .queue-remove {
            padding: 8px; background: transparent; border: none;
            color: var(--text-muted); cursor: pointer;
            border-radius: var(--radius-sm);
            transition: all 0.15s ease;
        }
        .queue-remove:hover { background: var(--danger-muted); color: var(--danger); }

        .queue-empty { padding: 48px 24px; text-align: center; color: var(--text-muted); }
        .queue-empty-icon { font-size: 32px; margin-bottom: 12px; opacity: 0.5; }

        /* Form Elements */
        .form-group { margin-bottom: 20px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { display: block; font-size: 13px; font-weight: 500; color: var(--text-primary); margin-bottom: 8px; }
        .form-hint { font-size: 12px; color: var(--text-muted); margin-top: 6px; }

        select, input[type="text"], input[type="number"] {
            width: 100%; padding: 10px 14px;
            font-family: var(--font-sans); font-size: 14px;
            background: var(--bg-base); border: 1px solid var(--border);
            border-radius: var(--radius-md); color: var(--text-primary);
            transition: all 0.2s ease; outline: none;
        }

        select:focus, input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-muted); }

        .checkbox-grid { display: flex; flex-wrap: wrap; gap: 8px; }

        .checkbox-btn {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 14px;
            background: var(--bg-base); border: 1px solid var(--border);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 13px;
        }

        .checkbox-btn:hover { border-color: var(--border-hover); }
        .checkbox-btn input { display: none; }
        .checkbox-btn.checked { background: var(--accent-muted); border-color: var(--accent); color: var(--accent); }
        .format-badge { font-family: var(--font-mono); font-size: 12px; font-weight: 500; text-transform: uppercase; }

        .toggle-row { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; border-bottom: 1px solid var(--border); }
        .toggle-row:last-child { border-bottom: none; padding-bottom: 0; }
        .toggle-row:first-child { padding-top: 0; }
        .toggle-info h4 { font-size: 14px; font-weight: 500; margin-bottom: 2px; }
        .toggle-info p { font-size: 12px; color: var(--text-muted); }

        .toggle { position: relative; width: 44px; height: 24px; cursor: pointer; flex-shrink: 0; }
        .toggle input { display: none; }
        .toggle-track { position: absolute; inset: 0; background: var(--bg-surface); border-radius: 24px; transition: background 0.2s ease; }
        .toggle-thumb { position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; background: var(--text-secondary); border-radius: 50%; transition: all 0.2s ease; }
        .toggle input:checked + .toggle-track { background: var(--accent); }
        .toggle input:checked + .toggle-track .toggle-thumb { transform: translateX(20px); background: white; }

        .slider-row { display: flex; align-items: center; gap: 16px; }
        .slider-value { min-width: 52px; padding: 6px 10px; background: var(--bg-base); border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: var(--font-mono); font-size: 13px; text-align: center; }

        input[type="range"] { flex: 1; height: 6px; background: var(--bg-surface); border-radius: 3px; outline: none; -webkit-appearance: none; }
        input[type="range"]::-webkit-slider-thumb { -webkit-appearance: none; width: 18px; height: 18px; background: var(--accent); border-radius: 50%; cursor: pointer; transition: transform 0.15s ease; }
        input[type="range"]::-webkit-slider-thumb:hover { transform: scale(1.15); }

        .resize-options { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
        .resize-options.visible { display: block; }
        .dimension-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .responsive-presets { display: none; margin-top: 12px; }
        .responsive-presets.visible { display: block; }

        /* Action Bar */
        .action-bar {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 24px; padding: 20px 24px;
            background: var(--bg-elevated); border: 1px solid var(--border); border-radius: var(--radius-lg);
        }
        .action-bar-info { font-size: 14px; color: var(--text-secondary); }
        .action-bar-info strong { color: var(--text-primary); }
        .action-bar-buttons { display: flex; gap: 12px; }

        /* Results */
        .results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .results-header h2 { font-size: 20px; font-weight: 600; }
        .results-stats { display: flex; gap: 24px; font-size: 14px; color: var(--text-secondary); }
        .results-stats strong { color: var(--text-primary); }

        .result-card {
            background: var(--bg-elevated); border: 1px solid var(--border);
            border-radius: var(--radius-lg); margin-bottom: 16px; overflow: hidden;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        .result-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
        .result-file-info { display: flex; align-items: center; gap: 12px; }
        .result-thumb { width: 48px; height: 48px; border-radius: var(--radius-md); object-fit: cover; background: var(--bg-surface); }
        .result-name { font-size: 14px; font-weight: 500; margin-bottom: 2px; }
        .result-meta { font-size: 12px; color: var(--text-muted); }
        .result-badge { padding: 4px 10px; font-size: 12px; font-weight: 600; border-radius: var(--radius-sm); }
        .result-badge.success { background: var(--accent-muted); color: var(--accent); }
        .result-body { padding: 20px; }

        .output-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .output-item { background: var(--bg-base); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; transition: all 0.2s ease; }
        .output-item:hover { border-color: var(--border-hover); }
        .output-format { display: inline-block; padding: 3px 8px; background: var(--bg-surface); border-radius: var(--radius-sm); font-family: var(--font-mono); font-size: 11px; font-weight: 500; text-transform: uppercase; margin-bottom: 8px; }
        .output-size { font-size: 18px; font-weight: 600; margin-bottom: 4px; }
        .output-reduction { font-size: 12px; color: var(--accent); }
        .output-reduction.negative { color: var(--danger); }
        .output-dims { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
        .output-actions { display: flex; gap: 8px; margin-top: 12px; }
        .output-actions .btn { flex: 1; padding: 8px 12px; font-size: 12px; justify-content: center; }

        .comparison { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
        .comparison-title { font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 12px; }
        .comparison-slider { position: relative; aspect-ratio: 16/10; border-radius: var(--radius-md); overflow: hidden; cursor: ew-resize; background: var(--bg-surface); }
        .comparison-img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: contain; }
        .comparison-before { position: absolute; inset: 0; }
        .comparison-after { position: absolute; top: 0; left: 0; width: 50%; height: 100%; overflow: hidden; border-right: 2px solid var(--accent); }
        .comparison-handle { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 40px; height: 40px; background: var(--accent); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #000; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.4); z-index: 10; }
        .comparison-labels { display: flex; justify-content: space-between; margin-top: 10px; font-size: 12px; color: var(--text-muted); }

        .code-block { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
        .code-tabs { display: flex; gap: 4px; margin-bottom: 12px; }
        .code-tab { padding: 6px 14px; background: transparent; border: none; border-radius: var(--radius-sm); font-family: var(--font-sans); font-size: 13px; color: var(--text-muted); cursor: pointer; transition: all 0.2s ease; }
        .code-tab:hover { color: var(--text-primary); }
        .code-tab.active { background: var(--bg-surface); color: var(--text-primary); }
        .code-content { position: relative; display: none; }
        .code-content.active { display: block; }
        .code-content pre { background: var(--bg-base); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; overflow-x: auto; font-family: var(--font-mono); font-size: 13px; line-height: 1.5; color: var(--text-secondary); }
        .btn-copy { position: absolute; top: 8px; right: 8px; padding: 6px 12px; font-size: 12px; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text-secondary); cursor: pointer; transition: all 0.2s ease; }
        .btn-copy:hover { background: var(--bg-hover); color: var(--text-primary); }
        .btn-copy.copied { background: var(--accent); border-color: var(--accent); color: #000; }

        .download-all-bar { display: flex; justify-content: center; margin-top: 24px; }

        /* Toast */
        .toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 1000; display: flex; flex-direction: column-reverse; gap: 12px; }
        .toast { display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: var(--bg-elevated); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: 0 8px 24px rgba(0,0,0,0.4); animation: toastIn 0.3s ease; min-width: 280px; }
        @keyframes toastIn { from { opacity: 0; transform: translateY(12px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .toast-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; }
        .toast.success .toast-icon { background: var(--accent); color: #000; }
        .toast.error .toast-icon { background: var(--danger); color: #fff; }
        .toast-text strong { display: block; font-size: 14px; font-weight: 500; margin-bottom: 2px; }
        .toast-text span { font-size: 13px; color: var(--text-muted); }

        .spinner { width: 16px; height: 16px; border: 2px solid var(--bg-surface); border-top-color: var(--info); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        input[type="file"] { display: none; }

        @media (max-width: 768px) {
            .app { padding: 20px 16px; }
            .header { flex-direction: column; align-items: flex-start; gap: 16px; }
            .workflow { flex-wrap: wrap; justify-content: flex-start; }
            .workflow-divider { display: none; }
            .action-bar { flex-direction: column; gap: 16px; text-align: center; }
            .output-grid { grid-template-columns: 1fr; }
        }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-base); }
        ::-webkit-scrollbar-thumb { background: var(--bg-surface); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--bg-hover); }
    </style>
</head>
<body>
    <div class="app">
        <header class="header">
            <div class="logo">
                <div class="logo-icon">⚡</div>
                <div>
                    <h1>Optix</h1>
                    <span>Image Optimizer Pro</span>
                </div>
            </div>
            <div class="header-actions">
                <a href="gallery.php" class="btn btn-secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Gallery
                </a>
            </div>
        </header>

        <div class="status-bar">
            <div class="status-item"><span class="status-dot <?= $serverStatus['gd_loaded'] ? 'active' : 'inactive' ?>"></span>GD Library <?= $serverStatus['gd_loaded'] ? 'Active' : 'Missing' ?></div>
            <div class="status-item"><span class="status-dot <?= $serverStatus['webp_support'] ? 'active' : 'warning' ?>"></span>WebP <?= $serverStatus['webp_support'] ? 'Supported' : 'Unavailable' ?></div>
            <div class="status-item"><span class="status-dot <?= $serverStatus['avif_support'] ? 'active' : 'warning' ?>"></span>AVIF <?= $serverStatus['avif_support'] ? 'Supported' : 'Unavailable' ?></div>
        </div>

        <div class="workflow">
            <div class="workflow-step active" data-step="1"><span class="workflow-step-number">1</span><span>Upload</span></div>
            <div class="workflow-divider"></div>
            <div class="workflow-step" data-step="2"><span class="workflow-step-number">2</span><span>Configure</span></div>
            <div class="workflow-divider"></div>
            <div class="workflow-step" data-step="3"><span class="workflow-step-number">3</span><span>Results</span></div>
        </div>

        <!-- Step 1: Upload -->
        <div class="main-content active" id="step1">
            <div class="upload-section">
                <div class="drop-zone" id="dropZone">
                    <div class="drop-zone-icon">📁</div>
                    <h3>Drop images here to get started</h3>
                    <p>or <span>click to browse</span> your files</p>
                    <p style="margin-top: 12px; font-size: 13px;">Supports JPEG, PNG, GIF, WebP · Max 10MB per file</p>
                </div>
                <input type="file" id="fileInput" multiple accept="image/jpeg,image/png,image/gif,image/webp">
            </div>
        </div>

        <!-- Step 2: Queue & Settings -->
        <div class="main-content" id="step2">
            <div class="queue-settings-layout">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Upload Queue</span>
                        <div class="card-actions">
                            <button class="btn btn-ghost btn-sm" onclick="addMoreFiles()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>Add More</button>
                            <button class="btn btn-ghost btn-sm" onclick="clearQueue()">Clear All</button>
                        </div>
                    </div>
                    <div class="queue-list" id="queueList"><div class="queue-empty"><div class="queue-empty-icon">📭</div><p>No images in queue</p></div></div>
                </div>

                <div class="card">
                    <div class="card-header"><span class="card-title">Optimization Settings</span></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">Quality</label>
                            <div class="slider-row">
                                <input type="range" id="quality" min="1" max="100" value="80">
                                <span class="slider-value" id="qualityValue">80%</span>
                            </div>
                            <p class="form-hint">Lower = smaller file, higher = better quality</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Output Formats</label>
                            <div class="checkbox-grid" id="formatOptions">
                                <label class="checkbox-btn checked"><input type="checkbox" name="format" value="webp" checked><span class="format-badge">WebP</span></label>
                                <?php if ($serverStatus['avif_support']): ?><label class="checkbox-btn"><input type="checkbox" name="format" value="avif"><span class="format-badge">AVIF</span></label><?php endif; ?>
                                <label class="checkbox-btn"><input type="checkbox" name="format" value="jpeg"><span class="format-badge">JPEG</span></label>
                                <label class="checkbox-btn"><input type="checkbox" name="format" value="png"><span class="format-badge">PNG</span></label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Resize</label>
                            <select id="resizeMode">
                                <option value="">Keep original dimensions</option>
                                <option value="fit">Fit within max dimensions</option>
                                <option value="width">Scale to width</option>
                                <option value="height">Scale to height</option>
                                <option value="exact">Exact dimensions (may distort)</option>
                            </select>
                            <div class="resize-options" id="resizeOptions">
                                <div class="dimension-grid">
                                    <div><label class="form-label">Max Width</label><input type="number" id="maxWidth" value="1920" min="1" max="4096"></div>
                                    <div><label class="form-label">Max Height</label><input type="number" id="maxHeight" value="1080" min="1" max="4096"></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="toggle-row">
                                <div class="toggle-info"><h4>Strip EXIF data</h4><p>Remove metadata for smaller files & privacy</p></div>
                                <label class="toggle"><input type="checkbox" id="stripExif" checked><span class="toggle-track"><span class="toggle-thumb"></span></span></label>
                            </div>
                            <div class="toggle-row">
                                <div class="toggle-info"><h4>Generate responsive sizes</h4><p>Create multiple sizes for srcset</p></div>
                                <label class="toggle"><input type="checkbox" id="responsiveSizes"><span class="toggle-track"><span class="toggle-thumb"></span></span></label>
                            </div>
                        </div>

                        <div class="responsive-presets" id="responsivePresets">
                            <div class="form-group">
                                <label class="form-label">Size Preset</label>
                                <select id="responsivePreset">
                                    <option value="standard">Standard (400, 800, 1200, 1600)</option>
                                    <option value="thumbnail">Thumbnails (150, 300, 600)</option>
                                    <option value="social">Social Media (640, 1080, 1920)</option>
                                    <option value="hero">Hero Images (800, 1200, 1920, 2560)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">File Naming</label>
                            <select id="namingPattern">
                                <option value="{name}">Original name</option>
                                <option value="{name}-{hash}">Name + hash</option>
                                <option value="{name}-{width}x{height}">Name + dimensions</option>
                                <option value="{hash}">Hash only</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="action-bar">
                <div class="action-bar-info"><strong id="queueCount">0</strong> images ready to optimize</div>
                <div class="action-bar-buttons">
                    <button class="btn btn-secondary" onclick="goToStep(1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>Back</button>
                    <button class="btn btn-primary btn-lg" id="startOptimizeBtn" onclick="startOptimization()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>Start Optimization</button>
                </div>
            </div>
        </div>

        <!-- Step 3: Results -->
        <div class="main-content" id="step3">
            <div class="results-header">
                <h2>Optimization Results</h2>
                <div class="results-stats"><span><strong id="processedCount">0</strong> processed</span><span><strong id="totalSaved">0 KB</strong> saved</span></div>
            </div>
            <div id="resultsList"></div>
            <div class="download-all-bar" id="downloadAllBar" style="display: none;">
                <button class="btn btn-primary btn-lg" id="downloadAllBtn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Download All (<span id="downloadCount">0</span>)</button>
            </div>
            <div style="text-align: center; margin-top: 24px;">
                <button class="btn btn-secondary" onclick="startOver()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>Optimize More Images</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        let pendingFiles = [], completedFiles = [], totalSavedBytes = 0, currentStep = 1;
        const dropZone = document.getElementById('dropZone'), fileInput = document.getElementById('fileInput'), queueList = document.getElementById('queueList'), resultsList = document.getElementById('resultsList'), toastContainer = document.getElementById('toastContainer');
        const qualitySlider = document.getElementById('quality'), qualityValue = document.getElementById('qualityValue');

        qualitySlider.addEventListener('input', () => { qualityValue.textContent = qualitySlider.value + '%'; updateSliderBackground(qualitySlider); });
        function updateSliderBackground(slider) { const percent = ((slider.value - slider.min) / (slider.max - slider.min)) * 100; slider.style.background = `linear-gradient(to right, var(--accent) ${percent}%, var(--bg-surface) ${percent}%)`; }
        updateSliderBackground(qualitySlider);

        document.querySelectorAll('.checkbox-btn').forEach(label => { const input = label.querySelector('input'); input.addEventListener('change', () => label.classList.toggle('checked', input.checked)); });

        const resizeMode = document.getElementById('resizeMode'), resizeOptions = document.getElementById('resizeOptions');
        resizeMode.addEventListener('change', () => resizeOptions.classList.toggle('visible', resizeMode.value !== ''));

        const responsiveSizes = document.getElementById('responsiveSizes'), responsivePresets = document.getElementById('responsivePresets');
        responsiveSizes.addEventListener('change', () => responsivePresets.classList.toggle('visible', responsiveSizes.checked));

        function goToStep(step) {
            currentStep = step;
            document.querySelectorAll('.workflow-step').forEach(el => { const s = parseInt(el.dataset.step); el.classList.remove('active', 'completed'); if (s === step) el.classList.add('active'); if (s < step) el.classList.add('completed'); });
            document.querySelectorAll('.main-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`step${step}`).classList.add('active');
        }

        dropZone.addEventListener('click', () => fileInput.click());
        dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
        dropZone.addEventListener('drop', e => { e.preventDefault(); dropZone.classList.remove('drag-over'); handleFiles(e.dataTransfer.files); });
        fileInput.addEventListener('change', () => { handleFiles(fileInput.files); fileInput.value = ''; });

        function addMoreFiles() { fileInput.click(); }

        async function handleFiles(files) {
            if (!files.length) return;
            for (const file of files) {
                if (!file.type.startsWith('image/')) { showToast('Invalid File', `${file.name} is not an image`, 'error'); continue; }
                if (file.size > 10485760) { showToast('File Too Large', `${file.name} exceeds 10MB limit`, 'error'); continue; }
                const preview = await createPreview(file);
                const id = 'file-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
                pendingFiles.push({ id, file, preview, status: 'pending' });
            }
            if (pendingFiles.length > 0) { goToStep(2); renderQueue(); }
        }

        function renderQueue() {
            if (pendingFiles.length === 0) { queueList.innerHTML = `<div class="queue-empty"><div class="queue-empty-icon">📭</div><p>No images in queue</p></div>`; document.getElementById('queueCount').textContent = '0'; return; }
            queueList.innerHTML = pendingFiles.map(item => `<div class="queue-item" data-id="${item.id}"><img src="${item.preview}" class="queue-thumb" alt=""><div class="queue-info"><div class="queue-name">${escapeHtml(item.file.name)}</div><div class="queue-meta"><span>${formatBytes(item.file.size)}</span></div></div><div class="queue-status"><span class="queue-status-badge ${item.status}">${getStatusLabel(item.status)}</span></div>${item.status === 'pending' ? `<button class="queue-remove" onclick="removeFromQueue('${item.id}')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>` : ''}</div>`).join('');
            document.getElementById('queueCount').textContent = pendingFiles.filter(f => f.status === 'pending').length;
        }

        function getStatusLabel(status) { return { pending: 'Ready', processing: 'Processing...', done: 'Done', error: 'Error' }[status] || status; }
        function removeFromQueue(id) { pendingFiles = pendingFiles.filter(f => f.id !== id); renderQueue(); if (pendingFiles.length === 0) goToStep(1); }
        function clearQueue() { pendingFiles = []; renderQueue(); goToStep(1); }

        function getSettings() {
            const formats = []; document.querySelectorAll('input[name="format"]:checked').forEach(cb => formats.push(cb.value));
            return { quality: document.getElementById('quality').value, formats: formats.length ? formats : ['webp'], resize_mode: document.getElementById('resizeMode').value || null, max_width: document.getElementById('maxWidth').value, max_height: document.getElementById('maxHeight').value, strip_exif: document.getElementById('stripExif').checked ? '1' : '0', responsive_sizes: document.getElementById('responsiveSizes').checked ? '1' : '0', responsive_preset: document.getElementById('responsivePreset').value, naming_pattern: document.getElementById('namingPattern').value };
        }

        async function startOptimization() {
            const pending = pendingFiles.filter(f => f.status === 'pending');
            if (pending.length === 0) { showToast('No Images', 'Add images to the queue first', 'error'); return; }
            const btn = document.getElementById('startOptimizeBtn');
            btn.disabled = true; btn.innerHTML = '<div class="spinner"></div> Processing...';
            const settings = getSettings();
            resultsList.innerHTML = ''; completedFiles = []; totalSavedBytes = 0;
            goToStep(3);
            for (const item of pending) {
                item.status = 'processing'; renderQueue();
                try {
                    const result = await processFile(item, settings);
                    if (result.success) { item.status = 'done'; completedFiles.push(...result.results.map(r => r.filename)); const savedBytes = result.results.reduce((acc, r) => acc + (result.original.size - r.size), 0); totalSavedBytes += Math.max(0, savedBytes); addResultCard(item, result); }
                    else { item.status = 'error'; addErrorCard(item, result.errors); }
                } catch (error) { item.status = 'error'; addErrorCard(item, ['Network error']); }
                renderQueue(); updateResultsStats();
            }
            btn.disabled = false; btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>Start Optimization`;
            if (completedFiles.length >= 2) { document.getElementById('downloadAllBar').style.display = 'flex'; document.getElementById('downloadCount').textContent = completedFiles.length; }
            showToast('Complete', `${completedFiles.length} images optimized`, 'success');
        }

        async function processFile(item, settings) {
            const formData = new FormData();
            formData.append('ajax', '1'); formData.append('image_file', item.file); formData.append('quality', settings.quality); formData.append('formats', JSON.stringify(settings.formats));
            if (settings.resize_mode) formData.append('resize_mode', settings.resize_mode);
            formData.append('max_width', settings.max_width); formData.append('max_height', settings.max_height); formData.append('strip_exif', settings.strip_exif); formData.append('responsive_sizes', settings.responsive_sizes); formData.append('responsive_preset', settings.responsive_preset); formData.append('naming_pattern', settings.naming_pattern);
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            return await response.json();
        }

        function addResultCard(item, result) {
            const outputHtml = result.results.map(r => `<div class="output-item"><span class="output-format">${r.format}</span><div class="output-size">${r.size_formatted}</div><div class="output-reduction ${r.reduction < 0 ? 'negative' : ''}">${r.reduction >= 0 ? '↓' : '↑'} ${Math.abs(r.reduction).toFixed(1)}%</div><div class="output-dims">${r.width} × ${r.height}</div><div class="output-actions"><a href="${r.url}" download class="btn btn-secondary btn-sm">Download</a></div></div>`).join('');
            const comparisonHtml = `<div class="comparison"><div class="comparison-title">Before / After</div><div class="comparison-slider" id="comparison-${item.id}"><div class="comparison-before"><img src="${item.preview}" class="comparison-img" alt="Before"></div><div class="comparison-after"><img src="${result.results[0].url}" class="comparison-img" alt="After"></div><div class="comparison-handle">⟷</div></div><div class="comparison-labels"><span>Original: ${result.original.size_formatted}</span><span>Optimized: ${result.results[0].size_formatted}</span></div></div>`;
            const codeHtml = createCodeBlock(result.results);
            const cardHtml = `<div class="result-card"><div class="result-header"><div class="result-file-info"><img src="${item.preview}" class="result-thumb" alt=""><div><div class="result-name">${escapeHtml(item.file.name)}</div><div class="result-meta">${result.original.width} × ${result.original.height} · ${result.original.size_formatted}</div></div></div><span class="result-badge success">−${Math.max(0, result.results[0].reduction).toFixed(0)}%</span></div><div class="result-body"><div class="output-grid">${outputHtml}</div>${comparisonHtml}${codeHtml}</div></div>`;
            resultsList.insertAdjacentHTML('beforeend', cardHtml);
            setTimeout(() => initComparisonSlider(document.getElementById(`comparison-${item.id}`)), 100);
        }

        function addErrorCard(item, errors) {
            const cardHtml = `<div class="result-card"><div class="result-header"><div class="result-file-info"><img src="${item.preview}" class="result-thumb" alt=""><div><div class="result-name">${escapeHtml(item.file.name)}</div><div class="result-meta">Error during processing</div></div></div><span class="result-badge" style="background: var(--danger-muted); color: var(--danger);">Failed</span></div><div class="result-body"><p style="color: var(--danger);">${errors.join('<br>')}</p></div></div>`;
            resultsList.insertAdjacentHTML('beforeend', cardHtml);
        }

        function createCodeBlock(results) {
            const webp = results.filter(r => r.format === 'webp'), avif = results.filter(r => r.format === 'avif'), fallback = results.filter(r => ['jpeg', 'jpg', 'png'].includes(r.format));
            let htmlCode = '';
            if (results.length > 1 && results.some(r => r.descriptor)) { const srcset = webp.map(r => `${r.url} ${r.descriptor}`).join(', '); htmlCode = `<img\n  srcset="${srcset}"\n  sizes="(max-width: 600px) 100vw, 50vw"\n  src="${results[0].url}"\n  alt=""\n  loading="lazy">`; }
            else { const webpImg = webp[0], avifImg = avif[0], fallbackImg = fallback[0] || webpImg; if (avifImg && webpImg) { htmlCode = `<picture>\n  <source srcset="${avifImg.url}" type="image/avif">\n  <source srcset="${webpImg.url}" type="image/webp">\n  <img src="${fallbackImg?.url || webpImg.url}" alt="" loading="lazy">\n</picture>`; } else if (webpImg) { htmlCode = `<picture>\n  <source srcset="${webpImg.url}" type="image/webp">\n  <img src="${fallbackImg?.url || webpImg.url}" alt="" loading="lazy">\n</picture>`; } else { htmlCode = `<img src="${results[0].url}" alt="" loading="lazy">`; } }
            const cssCode = `.bg-image {\n  background-image: url('${results[0].url}');\n  background-size: cover;\n  background-position: center;\n}`;
            const reactCode = htmlCode.replace(/srcset=/g, 'srcSet=');
            return `<div class="code-block"><div class="code-tabs"><button class="code-tab active" data-tab="html">HTML</button><button class="code-tab" data-tab="css">CSS</button><button class="code-tab" data-tab="react">React</button></div><div class="code-content active" data-content="html" data-code="${escapeHtml(htmlCode)}"><button class="btn-copy">Copy</button><pre><code>${escapeHtml(htmlCode)}</code></pre></div><div class="code-content" data-content="css" data-code="${escapeHtml(cssCode)}"><button class="btn-copy">Copy</button><pre><code>${escapeHtml(cssCode)}</code></pre></div><div class="code-content" data-content="react" data-code="${escapeHtml(reactCode)}"><button class="btn-copy">Copy</button><pre><code>${escapeHtml(reactCode)}</code></pre></div></div>`;
        }

        function initComparisonSlider(container) {
            if (!container) return;
            const handle = container.querySelector('.comparison-handle'), afterDiv = container.querySelector('.comparison-after');
            let isDragging = false;
            const updateSlider = x => { const rect = container.getBoundingClientRect(); const pos = Math.max(0, Math.min(x - rect.left, rect.width)); const percent = (pos / rect.width) * 100; handle.style.left = percent + '%'; afterDiv.style.width = percent + '%'; };
            handle.addEventListener('mousedown', () => isDragging = true);
            document.addEventListener('mousemove', e => { if (isDragging) updateSlider(e.clientX); });
            document.addEventListener('mouseup', () => isDragging = false);
            container.addEventListener('click', e => { if (e.target !== handle) updateSlider(e.clientX); });
            handle.addEventListener('touchstart', e => { isDragging = true; e.preventDefault(); });
            document.addEventListener('touchmove', e => { if (isDragging) updateSlider(e.touches[0].clientX); });
            document.addEventListener('touchend', () => isDragging = false);
        }

        document.addEventListener('click', e => {
            if (e.target.classList.contains('code-tab')) { const tabs = e.target.closest('.code-block').querySelectorAll('.code-tab'); const contents = e.target.closest('.code-block').querySelectorAll('.code-content'); tabs.forEach(t => t.classList.remove('active')); contents.forEach(c => c.classList.remove('active')); e.target.classList.add('active'); e.target.closest('.code-block').querySelector(`[data-content="${e.target.dataset.tab}"]`).classList.add('active'); }
            if (e.target.classList.contains('btn-copy')) { const content = e.target.closest('.code-content'); const code = content.dataset.code; navigator.clipboard.writeText(code).then(() => { e.target.textContent = 'Copied!'; e.target.classList.add('copied'); setTimeout(() => { e.target.textContent = 'Copy'; e.target.classList.remove('copied'); }, 2000); }); }
        });

        function updateResultsStats() { document.getElementById('processedCount').textContent = completedFiles.length; document.getElementById('totalSaved').textContent = formatBytes(totalSavedBytes); }
        document.getElementById('downloadAllBtn').addEventListener('click', () => { window.location.href = `?download_all=1&files=${encodeURIComponent(JSON.stringify(completedFiles))}`; });
        function startOver() { pendingFiles = []; completedFiles = []; totalSavedBytes = 0; resultsList.innerHTML = ''; document.getElementById('downloadAllBar').style.display = 'none'; goToStep(1); }
        function createPreview(file) { return new Promise(resolve => { const reader = new FileReader(); reader.onload = e => resolve(e.target.result); reader.readAsDataURL(file); }); }
        function formatBytes(bytes) { if (bytes === 0) return '0 B'; const k = 1024; const sizes = ['B', 'KB', 'MB', 'GB']; const i = Math.floor(Math.log(bytes) / Math.log(k)); return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]; }
        function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
        function showToast(title, message, type = 'success') { const toast = document.createElement('div'); toast.className = `toast ${type}`; toast.innerHTML = `<span class="toast-icon">${type === 'success' ? '✓' : '✕'}</span><div class="toast-text"><strong>${title}</strong><span>${message}</span></div>`; toastContainer.appendChild(toast); setTimeout(() => toast.remove(), 4000); }
    </script>
</body>
</html>