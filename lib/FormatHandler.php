<?php
declare(strict_types=1);

namespace Castle;

/**
 * Capability detection singleton.
 * Runs all environment checks once per request and caches results.
 * Used by controllers + JS (emitted as data-capabilities JSON on <body>).
 */
final class FormatHandler
{
    private static ?FormatHandler $instance = null;

    /** @var array<string,mixed> */
    private array $cache = [];

    public static function getInstance(): FormatHandler
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->cache = [
            'php_version'     => PHP_VERSION,
            'gd'              => extension_loaded('gd'),
            'gd_version'      => function_exists('gd_info') ? (gd_info()['GD Version'] ?? '') : '',
            'imagick'         => extension_loaded('imagick') && class_exists('Imagick'),
            'imagick_version' => '',
            'imagick_formats' => [],
            'ghostscript'     => false,
            'avif_write'      => function_exists('imageavif'),
            'avif_read'       => function_exists('imagecreatefromavif'),
            'webp_read'       => function_exists('imagecreatefromwebp'),
            'webp_write'      => function_exists('imagewebp'),
            'bmp_read'        => function_exists('imagecreatefrombmp'),
            'bmp_write'       => function_exists('imagebmp'),
            'zip'             => class_exists('ZipArchive'),
            'exif'            => extension_loaded('exif'),
            'fileinfo'        => extension_loaded('fileinfo'),
            'upload_max'      => $this->iniBytes((string) ini_get('upload_max_filesize')),
            'post_max'        => $this->iniBytes((string) ini_get('post_max_size')),
            'memory_limit'    => $this->iniBytes((string) ini_get('memory_limit')),
            'writable_output' => is_dir(CASTLE_OUTPUT_DIR) && is_writable(CASTLE_OUTPUT_DIR),
        ];

        if ($this->cache['imagick']) {
            try {
                $imagick = new \Imagick();
                $v = $imagick->getVersion();
                $this->cache['imagick_version'] = $v['versionString'] ?? '';
                $formats = \Imagick::queryFormats();
                $this->cache['imagick_formats'] = $formats;
                $this->cache['ghostscript'] = in_array('PDF', $formats, true);
            } catch (\Throwable $e) {
                $this->cache['imagick'] = false;
            }
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->cache[$key] ?? $default;
    }

    public function hasGd(): bool         { return (bool)$this->cache['gd']; }
    public function hasImagick(): bool    { return (bool)$this->cache['imagick']; }
    public function hasGhostscript(): bool{ return (bool)$this->cache['ghostscript']; }
    public function hasAvifWrite(): bool  { return (bool)$this->cache['avif_write']; }
    public function hasZip(): bool        { return (bool)$this->cache['zip']; }
    public function canRasterizePdf(): bool { return $this->hasImagick() && $this->hasGhostscript(); }
    public function canWritePdf(): bool   { return true; /* FPDF always bundled */ }

    /** @return string[] lowercase extensions accepted as input */
    public function getSupportedInputFormats(): array
    {
        $list = ['jpg', 'jpeg', 'png', 'gif'];
        if ($this->cache['webp_read']) $list[] = 'webp';
        if ($this->cache['avif_read']) $list[] = 'avif';
        if ($this->cache['bmp_read'])  $list[] = 'bmp';
        if ($this->hasImagick()) {
            $extra = ['tif', 'tiff', 'heic', 'heif', 'svg', 'psd', 'ico'];
            $im = $this->cache['imagick_formats'];
            foreach (['TIFF', 'HEIC', 'SVG', 'PSD', 'ICO'] as $fmt) {
                if (!in_array($fmt, $im, true)) {
                    // drop corresponding ext
                    $map = ['TIFF'=>['tif','tiff'], 'HEIC'=>['heic','heif'], 'SVG'=>['svg'], 'PSD'=>['psd'], 'ICO'=>['ico']];
                    $extra = array_diff($extra, $map[$fmt]);
                }
            }
            $list = array_values(array_unique(array_merge($list, $extra)));
        }
        if ($this->canRasterizePdf()) {
            $list[] = 'pdf';
        }
        return $list;
    }

    /** @return string[] lowercase format keys for output */
    public function getSupportedOutputFormats(): array
    {
        $list = ['jpeg', 'png', 'gif', 'webp'];
        if ($this->hasAvifWrite())         $list[] = 'avif';
        if ($this->cache['bmp_write'])     $list[] = 'bmp';
        $list[] = 'pdf';                   // always (FPDF)
        if ($this->hasImagick()) {
            $im = $this->cache['imagick_formats'];
            if (in_array('TIFF', $im, true)) $list[] = 'tiff';
            if (in_array('ICO',  $im, true)) $list[] = 'ico';
            if (in_array('HEIC', $im, true)) $list[] = 'heic';
        }
        return array_values(array_unique($list));
    }

    /** Structured report for the Server tab. */
    public function getCapabilityReport(): array
    {
        $ok   = 'ok';
        $warn = 'warn';
        $bad  = 'bad';

        $rows = [];

        $rows[] = [
            'label'  => 'PHP version',
            'value'  => $this->cache['php_version'],
            'status' => version_compare($this->cache['php_version'], '7.4', '>=') ? $ok : $bad,
            'hint'   => version_compare($this->cache['php_version'], '8.1', '>=') ? '' : 'Upgrade to PHP 8.1+ to unlock AVIF output.',
        ];
        $rows[] = [
            'label'  => 'GD extension',
            'value'  => $this->cache['gd'] ? ('enabled · ' . $this->cache['gd_version']) : 'missing',
            'status' => $this->cache['gd'] ? $ok : $bad,
            'hint'   => $this->cache['gd'] ? '' : 'Enable the php-gd extension in your cPanel "Select PHP Version" → Extensions.',
        ];
        $rows[] = [
            'label'  => 'Imagick',
            'value'  => $this->cache['imagick'] ? ('enabled · ' . $this->cache['imagick_version']) : 'missing',
            'status' => $this->cache['imagick'] ? $ok : $warn,
            'hint'   => $this->cache['imagick'] ? '' : 'Optional — enables TIFF, HEIC, SVG, PSD, ICO input and PDF rasterization.',
        ];
        $rows[] = [
            'label'  => 'Ghostscript (PDF rasterize)',
            'value'  => $this->cache['ghostscript'] ? 'available' : 'missing',
            'status' => $this->cache['ghostscript'] ? $ok : $warn,
            'hint'   => $this->cache['ghostscript'] ? '' : 'Optional — needed to convert PDF pages into images.',
        ];
        $rows[] = [
            'label'  => 'AVIF output',
            'value'  => $this->cache['avif_write'] ? 'supported' : 'missing',
            'status' => $this->cache['avif_write'] ? $ok : $warn,
            'hint'   => $this->cache['avif_write'] ? '' : 'Requires PHP 8.1+ compiled with libavif.',
        ];
        $rows[] = [
            'label'  => 'WebP output',
            'value'  => $this->cache['webp_write'] ? 'supported' : 'missing',
            'status' => $this->cache['webp_write'] ? $ok : $warn,
            'hint'   => '',
        ];
        $rows[] = [
            'label'  => 'ZIP support',
            'value'  => $this->cache['zip'] ? 'enabled' : 'missing',
            'status' => $this->cache['zip'] ? $ok : $warn,
            'hint'   => $this->cache['zip'] ? '' : 'Bulk download will be disabled.',
        ];
        $rows[] = [
            'label'  => 'fileinfo',
            'value'  => $this->cache['fileinfo'] ? 'enabled' : 'missing',
            'status' => $this->cache['fileinfo'] ? $ok : $warn,
            'hint'   => $this->cache['fileinfo'] ? '' : 'Falls back to extension-based MIME detection.',
        ];
        $rows[] = [
            'label'  => 'upload_max_filesize',
            'value'  => ini_get('upload_max_filesize') ?: '-',
            'status' => $this->cache['upload_max'] >= (8*1024*1024) ? $ok : $warn,
            'hint'   => 'Raise this in cPanel MultiPHP INI Editor if you need to upload larger files.',
        ];
        $rows[] = [
            'label'  => 'post_max_size',
            'value'  => ini_get('post_max_size') ?: '-',
            'status' => $this->cache['post_max'] >= $this->cache['upload_max'] ? $ok : $warn,
            'hint'   => 'Should be at least as large as upload_max_filesize.',
        ];
        $rows[] = [
            'label'  => 'memory_limit',
            'value'  => ini_get('memory_limit') ?: '-',
            'status' => $this->cache['memory_limit'] >= (128*1024*1024) ? $ok : $warn,
            'hint'   => 'Large PDFs / images may need 256M+.',
        ];
        $rows[] = [
            'label'  => 'Writable output dir',
            'value'  => $this->cache['writable_output'] ? CASTLE_OUTPUT_DIR : 'not writable',
            'status' => $this->cache['writable_output'] ? $ok : $bad,
            'hint'   => $this->cache['writable_output'] ? '' : 'chmod 775 the /optimized/ directory.',
        ];

        return [
            'rows'           => $rows,
            'input_formats'  => $this->getSupportedInputFormats(),
            'output_formats' => $this->getSupportedOutputFormats(),
            'can_pdf_in'     => $this->canRasterizePdf(),
            'can_pdf_out'    => $this->canWritePdf(),
        ];
    }

    /** Compact capability flags safe to embed in HTML data-* attributes. */
    public function getClientCapabilities(): array
    {
        return [
            'imagick'       => $this->hasImagick(),
            'ghostscript'   => $this->hasGhostscript(),
            'avifWrite'     => $this->hasAvifWrite(),
            'webpWrite'     => (bool)$this->cache['webp_write'],
            'zip'           => $this->hasZip(),
            'inputFormats'  => $this->getSupportedInputFormats(),
            'outputFormats' => $this->getSupportedOutputFormats(),
            'maxFileSize'   => CASTLE_MAX_FILE_SIZE,
            'maxDimension'  => CASTLE_MAX_DIMENSION,
            'maxPdfPages'   => CASTLE_MAX_PDF_PAGES,
        ];
    }

    private function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') return 0;
        $unit = strtolower(substr($v, -1));
        $n = (int)$v;
        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
