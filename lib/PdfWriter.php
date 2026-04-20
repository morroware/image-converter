<?php
declare(strict_types=1);

namespace Castle;

/**
 * Minimal, self-contained PDF writer for image → PDF conversion.
 *
 * Embeds JPEGs losslessly via /DCTDecode (no re-encode). Non-JPEG sources are
 * flattened onto white and re-encoded as JPEG via GD before embedding. This
 * keeps the writer tiny and avoids the complexity of PNG /FlateDecode +
 * /SMask soft-mask streams, which is the main pitfall of hand-rolled PDF
 * generators.
 *
 * Supported page sizes (points, 1pt = 1/72in):
 *   A4     = 595 x 842
 *   Letter = 612 x 792
 *   Legal  = 612 x 1008
 *   Custom = user-supplied mm (converted)
 *   Auto   = page sized to fit each image at 72 DPI
 *
 * When Imagick is available, callers should prefer Imagick::writeImages() —
 * this writer is the universal fallback.
 */
final class PdfWriter
{
    /** @var string[] */
    private array $objects = [];
    private int $objCounter = 0;
    /** @var int[] */
    private array $xref = [];

    private const PAGE_SIZES = [
        'a4'     => [595.28, 841.89],
        'letter' => [612.00, 792.00],
        'legal'  => [612.00, 1008.00],
    ];

    private const MARGINS = [
        'none'   => 0.0,
        'narrow' => 18.0,   // 0.25in
        'normal' => 54.0,   // 0.75in
    ];

    /**
     * @param array<int,array{path:string,ext:string}> $images   Input images, in page order.
     * @param array{
     *     page_size?:string,
     *     orientation?:string,
     *     margin?:string,
     *     custom_w_mm?:float,
     *     custom_h_mm?:float,
     *     title?:string
     * } $options
     */
    public function write(array $images, array $options, string $destPath): bool
    {
        if (!$images) return false;

        $pageSize    = strtolower((string)($options['page_size'] ?? 'auto'));
        $orientation = strtolower((string)($options['orientation'] ?? 'auto'));
        $margin      = self::MARGINS[$options['margin'] ?? 'narrow'] ?? self::MARGINS['narrow'];
        $title       = (string)($options['title'] ?? 'Castle Image Toolkit');

        // Normalize each image to a JPEG byte blob + dimensions.
        $prepared = [];
        foreach ($images as $src) {
            $jpg = $this->ensureJpeg($src['path'], $src['ext']);
            if ($jpg) $prepared[] = $jpg;
        }
        if (!$prepared) return false;

        // --- Build object tree -------------------------------------------------
        $this->objects = [];
        $this->objCounter = 0;
        $this->xref = [];

        // Reserve ids for catalog + pages container.
        $catalogId = $this->nextId();
        $pagesId   = $this->nextId();

        $pageIds = [];
        $pageObjectStrings = [];
        foreach ($prepared as $img) {
            [$pw, $ph] = $this->resolvePageSize($pageSize, $orientation, $img['w'], $img['h'], $options);

            // Compute fit-to-page rect with margin.
            $avW = $pw - 2 * $margin;
            $avH = $ph - 2 * $margin;
            $ratio = min($avW / $img['w'], $avH / $img['h']);
            $dw = $img['w'] * $ratio;
            $dh = $img['h'] * $ratio;
            $dx = ($pw - $dw) / 2;
            $dy = ($ph - $dh) / 2;

            // Image XObject.
            $imgId = $this->nextId();
            $this->objects[$imgId] = $this->buildImageObject($imgId, $img);

            // Content stream places image at dx/dy scaled to dw x dh.
            $contentId = $this->nextId();
            $content = sprintf("q\n%.2f 0 0 %.2f %.2f %.2f cm\n/I%d Do\nQ\n",
                $dw, $dh, $dx, $dy, $imgId);
            $this->objects[$contentId] = $this->buildStreamObject($contentId, $content);

            // Page.
            $pageId = $this->nextId();
            $pageIds[] = $pageId;
            $pageObjectStrings[$pageId] = sprintf(
                "%d 0 obj\n<</Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] "
                . "/Resources <</XObject <</I%d %d 0 R>> /ProcSet [/PDF /ImageB /ImageC]>> "
                . "/Contents %d 0 R>>\nendobj\n",
                $pageId, $pagesId, $pw, $ph, $imgId, $imgId, $contentId
            );
        }

        foreach ($pageObjectStrings as $pid => $body) {
            $this->objects[$pid] = $body;
        }

        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageIds));
        $this->objects[$pagesId] = sprintf(
            "%d 0 obj\n<</Type /Pages /Count %d /Kids [%s]>>\nendobj\n",
            $pagesId, count($pageIds), $kids
        );

        $this->objects[$catalogId] = sprintf(
            "%d 0 obj\n<</Type /Catalog /Pages %d 0 R>>\nendobj\n",
            $catalogId, $pagesId
        );

        $infoId = $this->nextId();
        $this->objects[$infoId] = sprintf(
            "%d 0 obj\n<</Title (%s) /Producer (Castle Image Toolkit) /CreationDate (D:%s)>>\nendobj\n",
            $infoId, $this->pdfString($title), date('YmdHis')
        );

        return $this->render($destPath, $catalogId, $infoId);
    }

    // -------------------------------------------------------------------------

    /**
     * Return JPEG bytes + dimensions + colorspace for the given source.
     * Pure JPEG sources pass through untouched; everything else is flattened
     * onto white and re-encoded at quality 88 via GD.
     *
     * @return array{data:string,w:int,h:int,cs:string,bpc:int}|null
     */
    private function ensureJpeg(string $path, string $ext): ?array
    {
        $ext = strtolower($ext);
        if ($ext === 'jpg') $ext = 'jpeg';

        if ($ext === 'jpeg') {
            $data = @file_get_contents($path);
            if (!$data) return null;
            $info = $this->parseJpegDimensions($data);
            if (!$info) return null;
            return ['data' => $data, 'w' => $info['w'], 'h' => $info['h'],
                    'cs' => $info['cs'], 'bpc' => 8];
        }

        // Load via GD (with Imagick fallback) and re-encode as JPEG.
        $img = $this->loadGd($path, $ext);
        if (!$img) return null;
        $w = imagesx($img); $h = imagesy($img);

        // Flatten onto white.
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        ob_start();
        imagejpeg($flat, null, 88);
        $jpeg = ob_get_clean();
        imagedestroy($flat);

        return ['data' => $jpeg, 'w' => $w, 'h' => $h, 'cs' => 'DeviceRGB', 'bpc' => 8];
    }

    private function loadGd(string $path, string $ext): ?\GdImage
    {
        $map = [
            'png'  => 'imagecreatefrompng',
            'gif'  => 'imagecreatefromgif',
            'webp' => 'imagecreatefromwebp',
            'avif' => 'imagecreatefromavif',
            'bmp'  => 'imagecreatefrombmp',
        ];
        if (isset($map[$ext]) && function_exists($map[$ext])) {
            $im = @call_user_func($map[$ext], $path);
            return $im ?: null;
        }
        // Imagick fallback
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick();
                $im->setBackgroundColor(new \ImagickPixel('white'));
                $im->readImage($path . '[0]');
                $im->setImageFormat('png');
                $blob = $im->getImageBlob();
                $im->clear();
                $gd = @imagecreatefromstring($blob);
                return $gd ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Extract width/height and color-components from a JPEG's SOF0/SOF2 marker.
     *
     * @return array{w:int,h:int,cs:string}|null
     */
    private function parseJpegDimensions(string $data): ?array
    {
        $len = strlen($data);
        if ($len < 4 || substr($data, 0, 2) !== "\xFF\xD8") return null;
        $i = 2;
        while ($i < $len - 1) {
            if ($data[$i] !== "\xFF") return null;
            // Skip fill bytes.
            while ($i < $len && $data[$i] === "\xFF") $i++;
            $marker = ord($data[$i]);
            $i++;
            // SOI/EOI have no length; standalone markers 0xD0-0xD9.
            if ($marker >= 0xD0 && $marker <= 0xD9) continue;
            if ($i + 2 > $len) return null;
            $segLen = (ord($data[$i]) << 8) | ord($data[$i + 1]);
            // SOF markers: C0..CF except C4, C8, CC.
            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                if ($i + 7 > $len) return null;
                $bpc = ord($data[$i + 2]);
                $h   = (ord($data[$i + 3]) << 8) | ord($data[$i + 4]);
                $w   = (ord($data[$i + 5]) << 8) | ord($data[$i + 6]);
                $comp = ord($data[$i + 7]);
                $cs = match ($comp) {
                    1 => 'DeviceGray',
                    4 => 'DeviceCMYK',
                    default => 'DeviceRGB',
                };
                return ['w' => $w, 'h' => $h, 'cs' => $cs];
            }
            $i += $segLen;
        }
        return null;
    }

    /**
     * Resolve the page dimensions for one image in PostScript points.
     *
     * @return array{0:float,1:float}
     */
    private function resolvePageSize(string $size, string $orient, int $imgW, int $imgH, array $options): array
    {
        if ($size === 'auto' || $size === '' || $size === 'fit') {
            // Size page at 72 DPI to the image, plus a tiny safety margin.
            return [(float)$imgW, (float)$imgH];
        }
        if ($size === 'custom') {
            $wMm = (float)($options['custom_w_mm'] ?? 210);
            $hMm = (float)($options['custom_h_mm'] ?? 297);
            $w = $wMm * 72 / 25.4;
            $h = $hMm * 72 / 25.4;
        } else {
            [$w, $h] = self::PAGE_SIZES[$size] ?? self::PAGE_SIZES['a4'];
        }

        if ($orient === 'landscape' || ($orient === 'auto' && $imgW > $imgH)) {
            if ($w < $h) [$w, $h] = [$h, $w];
        } elseif ($orient === 'portrait') {
            if ($w > $h) [$w, $h] = [$h, $w];
        }
        return [$w, $h];
    }

    private function buildImageObject(int $id, array $img): string
    {
        $stream = $img['data'];
        $header = sprintf(
            "%d 0 obj\n<</Type /XObject /Subtype /Image /Width %d /Height %d "
            . "/ColorSpace /%s /BitsPerComponent %d /Filter /DCTDecode /Length %d>>\nstream\n",
            $id, $img['w'], $img['h'], $img['cs'], $img['bpc'], strlen($stream)
        );
        return $header . $stream . "\nendstream\nendobj\n";
    }

    private function buildStreamObject(int $id, string $content): string
    {
        return sprintf(
            "%d 0 obj\n<</Length %d>>\nstream\n%s\nendstream\nendobj\n",
            $id, strlen($content), $content
        );
    }

    private function nextId(): int
    {
        return ++$this->objCounter;
    }

    private function pdfString(string $s): string
    {
        $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        return $s;
    }

    private function render(string $dest, int $catalogId, int $infoId): bool
    {
        $out  = "%PDF-1.4\n";
        $out .= "%\xE2\xE3\xCF\xD3\n"; // binary marker

        // Emit objects sorted by id so xref offsets are monotonic.
        ksort($this->objects);
        foreach ($this->objects as $id => $body) {
            $this->xref[$id] = strlen($out);
            $out .= $body;
        }

        $xrefOffset = strlen($out);
        $count = $this->objCounter + 1; // includes free obj 0
        $out .= "xref\n0 $count\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $offset = $this->xref[$i] ?? 0;
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        $out .= "trailer\n";
        $out .= sprintf("<</Size %d /Root %d 0 R /Info %d 0 R>>\n",
            $count, $catalogId, $infoId);
        $out .= "startxref\n$xrefOffset\n%%EOF\n";

        return (bool) @file_put_contents($dest, $out);
    }
}
