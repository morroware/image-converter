<?php
declare(strict_types=1);

namespace Castle;

/**
 * PDF → image rasterization via Imagick + Ghostscript.
 *
 * Gracefully refuses to operate when either dependency is missing.
 * Uses Imagick::pingImage() for cheap page enumeration (metadata only —
 * avoids rasterizing 200 pages just to count them) and caps page count at
 * CASTLE_MAX_PDF_PAGES to protect shared-host memory budgets.
 *
 * Critical ordering note: Imagick::setResolution() MUST be called BEFORE
 * readImage() — Imagick ignores resolution changes after the PDF is read.
 */
final class PdfRasterizer
{
    private FormatHandler $fmt;

    public function __construct(?FormatHandler $fmt = null)
    {
        $this->fmt = $fmt ?? FormatHandler::getInstance();
    }

    public function isAvailable(): bool
    {
        return $this->fmt->canRasterizePdf();
    }

    /**
     * Enumerate pages without rasterizing (uses pingImage).
     *
     * @return array{pages:int}
     */
    public function pageCount(string $pdfPath): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('PDF rasterization requires Imagick + Ghostscript.');
        }
        $im = new \Imagick();
        try {
            $im->pingImage($pdfPath);
            $count = $im->getNumberImages();
        } catch (\ImagickException $e) {
            throw $this->translateException($e);
        } finally {
            $im->clear();
        }
        return ['pages' => $count];
    }

    /**
     * Render a single page to a blob at low DPI — used for preview thumbnails.
     */
    public function renderThumbnail(string $pdfPath, int $pageIndex, int $dpi = 72): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('PDF rasterization requires Imagick + Ghostscript.');
        }
        $im = new \Imagick();
        try {
            $im->setResolution($dpi, $dpi);
            $im->readImage($pdfPath . '[' . $pageIndex . ']');
            $im->setImageFormat('png');
            $im->setBackgroundColor(new \ImagickPixel('white'));
            $im = $im->flattenImages();
            $blob = $im->getImageBlob();
        } catch (\ImagickException $e) {
            throw $this->translateException($e);
        } finally {
            $im->clear();
        }
        return $blob;
    }

    /**
     * Rasterize selected pages to files in CASTLE_OUTPUT_DIR.
     *
     * @param int[] $pages 0-indexed page numbers; empty = all pages.
     * @param string $outputFormat 'png'|'jpeg'|'webp'
     * @return array list of file descriptors for api.php response
     */
    public function rasterize(
        string $pdfPath,
        string $baseName,
        array $pages,
        int $dpi,
        string $outputFormat = 'png',
        int $quality = 90
    ): array {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('PDF rasterization requires Imagick + Ghostscript.');
        }

        $dpi = max(72, min(600, $dpi));
        $outputFormat = strtolower($outputFormat);
        if (!in_array($outputFormat, ['png', 'jpeg', 'webp'], true)) {
            throw new \RuntimeException('Unsupported output format.');
        }

        // Count pages cheaply.
        $info = $this->pageCount($pdfPath);
        $total = (int) $info['pages'];

        if ($total > CASTLE_MAX_PDF_PAGES) {
            throw new \RuntimeException(sprintf(
                'PDF has %d pages, exceeding the %d page safety limit. Split the PDF first.',
                $total, CASTLE_MAX_PDF_PAGES
            ));
        }

        if (!$pages) {
            $pages = range(0, $total - 1);
        } else {
            $pages = array_values(array_unique(array_filter(
                array_map('intval', $pages),
                fn($p) => $p >= 0 && $p < $total
            )));
        }

        $results = [];
        $safeBase = Uploader::safeName($baseName);

        foreach ($pages as $p) {
            $im = new \Imagick();
            try {
                $im->setResolution($dpi, $dpi);
                $im->readImage($pdfPath . '[' . $p . ']');
                $im->setImageFormat($outputFormat === 'jpeg' ? 'jpg' : $outputFormat);
                $im->setImageCompressionQuality($quality);
                if ($outputFormat === 'jpeg') {
                    $im->setImageBackgroundColor(new \ImagickPixel('white'));
                    $im = $im->flattenImages();
                    $im->setImageFormat('jpg');
                    $im->setImageCompressionQuality($quality);
                }
                $ext = $outputFormat === 'jpeg' ? 'jpg' : $outputFormat;
                $filename = sprintf('%s-page-%03d.%s', $safeBase, $p + 1, $ext);
                $destPath = rtrim(CASTLE_OUTPUT_DIR, '/') . '/' . $filename;
                $im->writeImage($destPath);
                $size = (int) filesize($destPath);
                $results[] = [
                    'page'           => $p + 1,
                    'filename'       => $filename,
                    'size'           => $size,
                    'size_formatted' => Uploader::formatBytes($size),
                    'width'          => $im->getImageWidth(),
                    'height'         => $im->getImageHeight(),
                    'url'            => rtrim(CASTLE_OUTPUT_URL, '/') . '/' . rawurlencode($filename),
                ];
            } catch (\ImagickException $e) {
                throw $this->translateException($e);
            } finally {
                $im->clear();
            }
        }

        return $results;
    }

    private function translateException(\ImagickException $e): \RuntimeException
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'password') || str_contains($msg, 'encrypted')) {
            return new \RuntimeException(
                'This PDF is password-protected. Please remove the password and re-upload.'
            );
        }
        if (str_contains($msg, 'postscript delegate') || str_contains($msg, 'ghostscript')) {
            return new \RuntimeException(
                'Ghostscript is not installed on this host — PDF rasterization unavailable.'
            );
        }
        return new \RuntimeException('PDF rasterization failed: ' . $e->getMessage());
    }
}
