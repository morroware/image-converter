<?php
declare(strict_types=1);

namespace Castle;

/**
 * ImageOptimizer — GD-first image pipeline with optional Imagick fallback.
 *
 * Pipeline:
 *   load  → pre-transforms (rotate/flip/crop/effects/watermark)
 *         → size loop (resize + format loop + smart compress)
 *         → write
 *
 * Preserves alpha for PNG/GIF/WebP/AVIF. All operations are in-memory aside
 * from the initial decode and the final write.
 */
final class ImageOptimizer
{
    /** @var string[] */
    public array $errors = [];
    /** @var string[] */
    public array $warnings = [];

    private FormatHandler $fmt;

    public function __construct(?FormatHandler $fmt = null)
    {
        $this->fmt = $fmt ?? FormatHandler::getInstance();
    }

    // -----------------------------------------------------------------------
    // Public entry point
    // -----------------------------------------------------------------------

    /**
     * Run the full pipeline for a single source file.
     *
     * $options schema:
     *   formats              string[]  e.g. ['webp','avif','jpeg']
     *   quality              int 1-100
     *   resize_mode          ''|'fit'|'width'|'height'|'exact'
     *   max_width/max_height int
     *   responsive_sizes     bool
     *   responsive_preset    'standard'|'thumbnail'|'social'|'hero'
     *   strip_exif           bool
     *   naming_pattern       string  (supports {name}{hash}{width}{height}{format})
     *   rotate               0|90|180|270
     *   flip                 ''|'horizontal'|'vertical'|'both'
     *   crop                 null | [x,y,w,h]
     *   effects              string[] subset of: grayscale sepia blur sharpen
     *   brightness           int -100..100
     *   contrast             int -100..100
     *   watermark_text       string
     *   watermark_position   'tl'|'tr'|'bl'|'br'|'center'
     *   watermark_opacity    int 0..100
     *   smart_target_kb      int (0 disables)
     *
     * @param array{tmp:string,name:string,size:int,mime:string,ext:string} $file
     * @param array<string,mixed> $options
     * @return array
     */
    public function optimize(array $file, array $options): array
    {
        $this->errors = $this->warnings = [];

        $sourcePath = $file['tmp'];
        $originalSize = $file['size'];
        $originalExt  = $file['ext'];

        // Load source into a GD image resource.
        try {
            [$img, $meta] = $this->loadImage($sourcePath, $originalExt);
        } catch (\Throwable $e) {
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }

        [$origW, $origH] = [$meta['width'], $meta['height']];

        // Apply pre-size transforms on a working copy once.
        $img = $this->applyPreTransforms($img, $options);
        $origW = imagesx($img);
        $origH = imagesy($img);

        $quality = max(1, min(100, (int)($options['quality'] ?? CASTLE_DEFAULT_QUALITY)));
        $formats = $this->normalizeFormats($options['formats'] ?? ['webp']);
        $sizes   = $this->determineSizes($origW, $origH, $options);

        $results = [];
        foreach ($sizes as $sz) {
            $resized = $this->resizeTo($img, $sz['width'], $sz['height']);

            foreach ($formats as $format) {
                $filename = $this->buildFilename($file['name'], $sourcePath, $format, [
                    'width'         => $sz['width'],
                    'height'        => $sz['height'],
                    'naming_pattern'=> $options['naming_pattern'] ?? '{name}',
                    'size_suffix'   => $sz['suffix'],
                ]);
                $dest = rtrim(CASTLE_OUTPUT_DIR, '/') . '/' . $filename;

                $saved = $this->writeImage($resized, $dest, $format, $quality, $options);
                if ($saved) {
                    // Smart compress loop (binary search quality to hit target_kb).
                    if (!empty($options['smart_target_kb'])) {
                        $this->smartCompress($resized, $dest, $format, (int)$options['smart_target_kb']);
                    }
                    $newSize = (int)filesize($dest);
                    $results[] = [
                        'format'         => $format,
                        'filename'       => $filename,
                        'size'           => $newSize,
                        'size_formatted' => Uploader::formatBytes($newSize),
                        'reduction'      => $originalSize ? (($originalSize - $newSize) / $originalSize) * 100 : 0,
                        'width'          => $sz['width'],
                        'height'         => $sz['height'],
                        'descriptor'     => $sz['descriptor'],
                        'url'            => rtrim(CASTLE_OUTPUT_URL, '/') . '/' . rawurlencode($filename),
                    ];
                }
            }

            if ($resized !== $img) imagedestroy($resized);
        }

        imagedestroy($img);

        if (!$results) {
            return ['success' => false, 'errors' => $this->errors ?: ['Conversion produced no output']];
        }

        return [
            'success'  => true,
            'original' => [
                'name'          => $file['name'],
                'size'          => $originalSize,
                'size_formatted'=> Uploader::formatBytes($originalSize),
                'width'         => $meta['width'],
                'height'        => $meta['height'],
                'mime'          => $meta['mime'],
                'has_exif'      => $meta['has_exif'],
            ],
            'results'  => $results,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Generate a favicon pack (PNGs at multiple sizes + optional .ico).
     *
     * @param array{tmp:string,name:string,size:int,mime:string,ext:string} $file
     * @return array
     */
    public function buildFaviconPack(array $file): array
    {
        try {
            [$img] = $this->loadImage($file['tmp'], $file['ext']);
        } catch (\Throwable $e) {
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }

        $basename = pathinfo($file['name'], PATHINFO_FILENAME);
        $sizes = [16, 32, 48, 180, 192, 512];
        $outputs = [];

        foreach ($sizes as $size) {
            $resized = $this->resizeTo($img, $size, $size);
            $name = "{$basename}-favicon-{$size}.png";
            $dest = rtrim(CASTLE_OUTPUT_DIR, '/') . '/' . $name;
            if ($this->writeImage($resized, $dest, 'png', 90, [])) {
                $outputs[] = [
                    'filename'       => $name,
                    'size'           => (int)filesize($dest),
                    'size_formatted' => Uploader::formatBytes((int)filesize($dest)),
                    'width'          => $size,
                    'height'         => $size,
                    'url'            => rtrim(CASTLE_OUTPUT_URL, '/') . '/' . rawurlencode($name),
                ];
            }
            if ($resized !== $img) imagedestroy($resized);
        }

        // .ico via Imagick if available.
        if ($this->fmt->hasImagick()) {
            try {
                $ico = new \Imagick();
                foreach ([16, 32, 48] as $s) {
                    $one = new \Imagick();
                    $one->readImage(rtrim(CASTLE_OUTPUT_DIR, '/') . "/{$basename}-favicon-{$s}.png");
                    $one->setImageFormat('ico');
                    $ico->addImage($one);
                }
                $icoName = "{$basename}-favicon.ico";
                $icoPath = rtrim(CASTLE_OUTPUT_DIR, '/') . '/' . $icoName;
                $ico->writeImages($icoPath, true);
                $outputs[] = [
                    'filename'       => $icoName,
                    'size'           => (int)filesize($icoPath),
                    'size_formatted' => Uploader::formatBytes((int)filesize($icoPath)),
                    'width'          => 48,
                    'height'         => 48,
                    'url'            => rtrim(CASTLE_OUTPUT_URL, '/') . '/' . rawurlencode($icoName),
                ];
            } catch (\Throwable $e) {
                $this->warnings[] = 'ICO generation failed: ' . $e->getMessage();
            }
        }

        // site.webmanifest
        $manifest = [
            'name'        => $basename,
            'short_name'  => $basename,
            'icons'       => [
                ['src' => "{$basename}-favicon-192.png", 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => "{$basename}-favicon-512.png", 'sizes' => '512x512', 'type' => 'image/png'],
            ],
            'theme_color' => '#6d28d9',
            'background_color' => '#0f0a1e',
            'display'     => 'standalone',
        ];
        $manifestName = "{$basename}-site.webmanifest";
        $manifestPath = rtrim(CASTLE_OUTPUT_DIR, '/') . '/' . $manifestName;
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $outputs[] = [
            'filename'       => $manifestName,
            'size'           => (int)filesize($manifestPath),
            'size_formatted' => Uploader::formatBytes((int)filesize($manifestPath)),
            'width'          => 0,
            'height'         => 0,
            'url'            => rtrim(CASTLE_OUTPUT_URL, '/') . '/' . rawurlencode($manifestName),
        ];

        imagedestroy($img);

        return ['success' => true, 'results' => $outputs, 'warnings' => $this->warnings];
    }

    // -----------------------------------------------------------------------
    // Loading
    // -----------------------------------------------------------------------

    /**
     * Load an arbitrary input file into a GD image resource.
     * Uses GD natively when possible, Imagick as a fallback for exotic formats.
     *
     * @return array{0: \GdImage, 1: array{width:int,height:int,mime:string,has_exif:bool}}
     */
    private function loadImage(string $path, string $ext): array
    {
        $ext = Uploader::canonExt($ext);
        $img = null;
        $mime = 'application/octet-stream';
        $hasExif = false;

        $gdMap = [
            'jpeg' => ['image/jpeg', 'imagecreatefromjpeg'],
            'png'  => ['image/png',  'imagecreatefrompng'],
            'gif'  => ['image/gif',  'imagecreatefromgif'],
            'webp' => ['image/webp', 'imagecreatefromwebp'],
            'avif' => ['image/avif', 'imagecreatefromavif'],
            'bmp'  => ['image/bmp',  'imagecreatefrombmp'],
        ];

        if (isset($gdMap[$ext]) && function_exists($gdMap[$ext][1])) {
            $mime = $gdMap[$ext][0];
            $img  = @call_user_func($gdMap[$ext][1], $path);
        } elseif ($this->fmt->hasImagick()) {
            $img  = $this->imagickToGd($path);
            $mime = 'application/octet-stream';
        }

        if (!$img) {
            throw new \RuntimeException('Could not decode source image.');
        }

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $hasExif = is_array($exif) && count($exif) > 0;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > CASTLE_MAX_DIMENSION || $h > CASTLE_MAX_DIMENSION) {
            imagedestroy($img);
            throw new \RuntimeException('Image exceeds maximum dimension.');
        }

        // Normalize to truecolor + alpha so all operations are safe.
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);

        return [$img, ['width' => $w, 'height' => $h, 'mime' => $mime, 'has_exif' => $hasExif]];
    }

    /** Imagick → PNG buffer → GD. Slow but universal fallback for TIFF/HEIC/SVG/PSD. */
    private function imagickToGd(string $path): ?\GdImage
    {
        try {
            $im = new \Imagick();
            $im->setBackgroundColor(new \ImagickPixel('transparent'));
            $im->readImage($path . '[0]'); // first frame/page only
            $im->setImageFormat('png');
            $blob = $im->getImageBlob();
            $im->clear();
            $gd = @imagecreatefromstring($blob);
            return $gd ?: null;
        } catch (\Throwable $e) {
            $this->errors[] = 'Imagick decode failed: ' . $e->getMessage();
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Pre-transforms
    // -----------------------------------------------------------------------

    private function applyPreTransforms(\GdImage $img, array $opts): \GdImage
    {
        // Crop first (reduces work downstream).
        if (!empty($opts['crop']) && is_array($opts['crop'])) {
            [$x, $y, $w, $h] = array_map('intval', [
                $opts['crop'][0] ?? 0, $opts['crop'][1] ?? 0,
                $opts['crop'][2] ?? imagesx($img), $opts['crop'][3] ?? imagesy($img),
            ]);
            if ($w > 0 && $h > 0) {
                $cropped = imagecrop($img, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
                if ($cropped) {
                    imagedestroy($img);
                    $img = $cropped;
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
            }
        }

        // Rotate.
        $rotate = (int)($opts['rotate'] ?? 0) % 360;
        if ($rotate !== 0) {
            $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
            $rotated = imagerotate($img, -$rotate, $transparent);
            if ($rotated) {
                imagedestroy($img);
                $img = $rotated;
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }
        }

        // Flip.
        $flip = $opts['flip'] ?? '';
        if ($flip === 'horizontal') imageflip($img, IMG_FLIP_HORIZONTAL);
        elseif ($flip === 'vertical') imageflip($img, IMG_FLIP_VERTICAL);
        elseif ($flip === 'both') imageflip($img, IMG_FLIP_BOTH);

        // Effects.
        $effects = is_array($opts['effects'] ?? null) ? $opts['effects'] : [];
        foreach ($effects as $fx) {
            switch ($fx) {
                case 'grayscale': imagefilter($img, IMG_FILTER_GRAYSCALE); break;
                case 'sepia':
                    imagefilter($img, IMG_FILTER_GRAYSCALE);
                    imagefilter($img, IMG_FILTER_COLORIZE, 90, 60, 30);
                    break;
                case 'blur':     imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR); break;
                case 'sharpen':
                    $matrix = [[-1,-1,-1], [-1, 16,-1], [-1,-1,-1]];
                    imageconvolution($img, $matrix, 8, 0);
                    break;
            }
        }

        if (isset($opts['brightness']) && (int)$opts['brightness'] !== 0) {
            imagefilter($img, IMG_FILTER_BRIGHTNESS, max(-255, min(255, (int)$opts['brightness'] * 2)));
        }
        if (isset($opts['contrast']) && (int)$opts['contrast'] !== 0) {
            imagefilter($img, IMG_FILTER_CONTRAST, max(-100, min(100, -(int)$opts['contrast'])));
        }

        // Watermark (text overlay).
        $wmText = trim((string)($opts['watermark_text'] ?? ''));
        if ($wmText !== '') {
            $this->drawTextWatermark($img, $wmText,
                (string)($opts['watermark_position'] ?? 'br'),
                max(0, min(100, (int)($opts['watermark_opacity'] ?? 50))));
        }

        return $img;
    }

    private function drawTextWatermark(\GdImage $img, string $text, string $pos, int $opacity): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $fontSize = max(10, (int)($w / 40));
        $font = 5; // built-in GD font
        $textW = imagefontwidth($font) * strlen($text);
        $textH = imagefontheight($font);

        $pad = 12;
        [$x, $y] = match ($pos) {
            'tl'     => [$pad, $pad],
            'tr'     => [$w - $textW - $pad, $pad],
            'bl'     => [$pad, $h - $textH - $pad],
            'center' => [(int)(($w - $textW) / 2), (int)(($h - $textH) / 2)],
            default  => [$w - $textW - $pad, $h - $textH - $pad], // br
        };

        $alpha = 127 - (int)round($opacity / 100 * 127);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, $alpha);
        $fg = imagecolorallocatealpha($img, 255, 255, 255, $alpha);
        imagestring($img, $font, $x + 1, $y + 1, $text, $shadow);
        imagestring($img, $font, $x, $y, $text, $fg);
    }

    // -----------------------------------------------------------------------
    // Resizing
    // -----------------------------------------------------------------------

    private function determineSizes(int $origW, int $origH, array $opts): array
    {
        if (!empty($opts['responsive_sizes'])) {
            $presets = [
                'standard'  => [400, 800, 1200, 1600],
                'thumbnail' => [150, 300, 600],
                'social'    => [640, 1080, 1920],
                'hero'      => [800, 1200, 1920, 2560],
            ];
            $widths = $presets[$opts['responsive_preset'] ?? 'standard'] ?? $presets['standard'];
            $sizes = [];
            foreach ($widths as $w) {
                if ($w <= $origW) {
                    $ratio = $w / $origW;
                    $sizes[] = [
                        'width' => $w, 'height' => (int)round($origH * $ratio),
                        'suffix' => '-' . $w . 'w', 'descriptor' => $w . 'w',
                    ];
                }
            }
            if (!$sizes || end($sizes)['width'] < $origW) {
                $sizes[] = ['width' => $origW, 'height' => $origH, 'suffix' => '', 'descriptor' => $origW . 'w'];
            }
            return $sizes;
        }

        $dim = $this->calculateDimensions($origW, $origH, $opts);
        return [[
            'width' => $dim['width'], 'height' => $dim['height'],
            'suffix' => '', 'descriptor' => $dim['width'] . 'w',
        ]];
    }

    private function calculateDimensions(int $origW, int $origH, array $opts): array
    {
        $w = $origW; $h = $origH;
        $mode = $opts['resize_mode'] ?? '';
        if (!$mode) return ['width' => $w, 'height' => $h];

        $maxW = min((int)($opts['max_width'] ?? CASTLE_MAX_DIMENSION), CASTLE_MAX_DIMENSION);
        $maxH = min((int)($opts['max_height'] ?? CASTLE_MAX_DIMENSION), CASTLE_MAX_DIMENSION);

        switch ($mode) {
            case 'fit':
                $r = min($maxW / $origW, $maxH / $origH);
                if ($r < 1) { $w = (int)round($origW * $r); $h = (int)round($origH * $r); }
                break;
            case 'width':
                $w = $maxW; $h = (int)round($origH * ($maxW / $origW)); break;
            case 'height':
                $h = $maxH; $w = (int)round($origW * ($maxH / $origH)); break;
            case 'exact':
                $w = $maxW; $h = $maxH;
                $this->warnings[] = 'Exact sizing may distort the image.';
                break;
        }
        return ['width' => max(1, $w), 'height' => max(1, $h)];
    }

    private function resizeTo(\GdImage $src, int $targetW, int $targetH): \GdImage
    {
        $w = imagesx($src); $h = imagesy($src);
        if ($w === $targetW && $h === $targetH) return $src;

        $dst = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $w, $h);
        return $dst;
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    private function writeImage(\GdImage $img, string $dest, string $format, int $quality, array $opts): bool
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);

        switch ($format) {
            case 'jpeg':
                // JPEG has no alpha — flatten against white.
                $flat = $this->flattenForJpeg($img);
                $ok = @imagejpeg($flat, $dest, $quality);
                if ($flat !== $img) imagedestroy($flat);
                return $ok;
            case 'webp':
                return @imagewebp($img, $dest, $quality);
            case 'avif':
                if (!function_exists('imageavif')) { $this->errors[] = 'AVIF not supported on this host.'; return false; }
                return @imageavif($img, $dest, $quality);
            case 'png':
                $pngQ = (int)round(9 - ($quality / 100 * 9));
                return @imagepng($img, $dest, $pngQ);
            case 'gif':
                return @imagegif($img, $dest);
            case 'bmp':
                return function_exists('imagebmp') ? @imagebmp($img, $dest) : false;
            case 'tiff':
            case 'ico':
            case 'heic':
                if (!$this->fmt->hasImagick()) { $this->errors[] = "$format requires Imagick."; return false; }
                return $this->writeWithImagick($img, $dest, $format, $quality);
            default:
                $this->errors[] = "Unknown format: $format";
                return false;
        }
    }

    private function flattenForJpeg(\GdImage $img): \GdImage
    {
        $w = imagesx($img); $h = imagesy($img);
        $flat = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        return $flat;
    }

    /** Route a GD image through Imagick for formats GD can't write. */
    private function writeWithImagick(\GdImage $img, string $dest, string $format, int $quality): bool
    {
        try {
            ob_start();
            imagepng($img, null, 1); // near-lossless intermediate
            $blob = ob_get_clean();
            $im = new \Imagick();
            $im->readImageBlob($blob);
            $im->setImageFormat($format);
            $im->setImageCompressionQuality($quality);
            $ok = $im->writeImage($dest);
            $im->clear();
            return $ok;
        } catch (\Throwable $e) {
            $this->errors[] = "Imagick $format write failed: " . $e->getMessage();
            return false;
        }
    }

    /** Binary-search quality to try to hit $targetKb. Best-effort. */
    private function smartCompress(\GdImage $img, string $dest, string $format, int $targetKb): void
    {
        if ($targetKb <= 0) return;
        if (!in_array($format, ['jpeg', 'webp', 'avif'], true)) return;

        $target = $targetKb * 1024;
        $lo = 20; $hi = 95; $best = null;
        for ($i = 0; $i < 6; $i++) {
            $q = (int)(($lo + $hi) / 2);
            $this->writeImage($img, $dest, $format, $q, []);
            $size = (int)filesize($dest);
            if ($size > $target) $hi = $q - 2; else { $lo = $q + 2; $best = $q; }
            if (abs($size - $target) < $target * 0.05) break;
        }
        if ($best) $this->writeImage($img, $dest, $format, $best, []);
    }

    private function buildFilename(string $origName, string $sourcePath, string $format, array $opts): string
    {
        $hash = substr(sha1_file($sourcePath) ?: uniqid('', true), 0, 8);
        $safe = Uploader::safeName($origName);
        $pattern = $opts['naming_pattern'] ?? '{name}';
        $filename = strtr($pattern, [
            '{name}'   => $safe,
            '{hash}'   => $hash,
            '{width}'  => (string)($opts['width'] ?? 'orig'),
            '{height}' => (string)($opts['height'] ?? 'orig'),
            '{format}' => $format,
        ]);
        $ext = $format === 'jpeg' ? 'jpg' : $format;
        return $filename . ($opts['size_suffix'] ?? '') . '.' . $ext;
    }

    /** @param string[] $formats */
    private function normalizeFormats(array $formats): array
    {
        $supported = $this->fmt->getSupportedOutputFormats();
        $out = [];
        foreach ($formats as $f) {
            $f = strtolower((string)$f);
            if ($f === 'jpg') $f = 'jpeg';
            if (in_array($f, $supported, true) && !in_array($f, $out, true)) {
                $out[] = $f;
            }
        }
        return $out ?: ['webp'];
    }
}
