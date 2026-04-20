<?php
/**
 * The Castle Fun Center — Image Toolkit
 * Convert / PDF / Server page (tabbed single-page app).
 */

require_once __DIR__ . '/bootstrap.php';

/** @var \Castle\FormatHandler $formats */
$formats = $GLOBALS['castle_formats'];
$capabilities = json_encode($formats->getClientCapabilities(), JSON_UNESCAPED_SLASHES);
$outputFormats = $formats->getSupportedOutputFormats();
$csrf = \Castle\Response::csrfToken();
$brand = CASTLE_BRAND_NAME;
$tagline = CASTLE_BRAND_TAGLINE;
$authEnabled = \Castle\Auth::isEnabled();
$canPdfIn = $formats->canRasterizePdf();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="castle-csrf" content="<?= $h($csrf) ?>">
<title><?= $h($brand) ?> — <?= $h($tagline) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/css/theme.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body data-capabilities='<?= $h($capabilities) ?>'>

<div class="castle-shell">

    <header class="castle-header">
        <div class="castle-brand">
            <img class="castle-brand-crest" src="assets/img/castle-crest.svg" alt="">
            <div class="castle-brand-text">
                <h1><?= $h($brand) ?></h1>
                <div class="tagline"><?= $h($tagline) ?></div>
            </div>
        </div>
        <nav class="castle-nav" aria-label="Primary">
            <a href="#convert" data-tab="convert" class="active">Convert</a>
            <a href="#pdf" data-tab="pdf">PDF</a>
            <a href="gallery.php">Gallery</a>
            <a href="#server" data-tab="server">Server</a>
            <?php if ($authEnabled): ?>
                <a href="logout.php" class="logout">Sign out</a>
            <?php endif; ?>
        </nav>
    </header>

    <!-- ============ CONVERT TAB ============ -->
    <section class="tab-section active" id="tab-convert" data-tab="convert">

        <div class="card card-hairline">
            <div class="dropzone" id="dropzone">
                <div class="dropzone-icon">★</div>
                <h2>Drop images here</h2>
                <p>JPG · PNG · WebP · AVIF · GIF · BMP<?php
                    if ($formats->hasImagick()) echo ' · TIFF · HEIC · SVG · PSD'; ?></p>
                <div class="alt-actions">
                    <button class="btn btn-gold" id="pick-files-btn" type="button">Choose files</button>
                    <span class="muted" style="font-size:0.82rem">or paste with Ctrl+V</span>
                </div>
                <input type="file" id="filepicker" multiple accept="image/*,.heic,.heif,.tif,.tiff,.psd,.svg" class="sr-only">
            </div>

            <div class="row" style="margin-top:16px">
                <input type="url" id="url-import" placeholder="Or paste an image URL here" class="grow">
                <button class="btn btn-ghost" id="url-import-btn" type="button">Fetch URL</button>
            </div>
        </div>

        <div class="work-layout">
            <div class="card">
                <h3>Queue</h3>
                <div class="queue" id="queue"></div>
            </div>

            <div class="card">
                <div class="settings-group">
                    <h3>Output formats</h3>
                    <div class="format-grid">
                        <?php foreach ($outputFormats as $fmt):
                            $label = strtoupper($fmt);
                            $checked = in_array($fmt, ['webp', 'avif', 'jpeg'], true);
                        ?>
                        <label class="format-pill">
                            <input type="checkbox" value="<?= $h($fmt) ?>" <?= $checked ? 'checked' : '' ?>>
                            <?= $h($label) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="settings-group">
                    <h3>Quality</h3>
                    <div class="slider-row">
                        <input type="range" id="quality" min="1" max="100" value="<?= (int)CASTLE_DEFAULT_QUALITY ?>">
                        <span class="slider-val" id="quality-val"><?= (int)CASTLE_DEFAULT_QUALITY ?></span>
                    </div>
                    <div class="hint">Lower = smaller file, higher = better image. Applies to JPEG/WebP/AVIF.</div>
                </div>

                <div class="settings-group">
                    <h3>Resize</h3>
                    <label>Mode</label>
                    <select id="resize_mode">
                        <option value="">Keep original</option>
                        <option value="fit">Fit within max dimensions</option>
                        <option value="width">Scale to width</option>
                        <option value="height">Scale to height</option>
                        <option value="exact">Exact (may distort)</option>
                    </select>
                    <div class="two-col" style="margin-top:8px">
                        <div>
                            <label>Max width (px)</label>
                            <input type="number" id="max_width" min="0" step="1" value="1920">
                        </div>
                        <div>
                            <label>Max height (px)</label>
                            <input type="number" id="max_height" min="0" step="1" value="1920">
                        </div>
                    </div>
                </div>

                <details class="fancy">
                    <summary>Responsive sizes</summary>
                    <div class="body">
                        <label><input type="checkbox" id="responsive_sizes"> Generate responsive widths</label>
                        <label style="margin-top:6px">Preset</label>
                        <select id="responsive_preset">
                            <option value="standard">Standard (400 · 800 · 1200 · 1600w)</option>
                            <option value="thumbnail">Thumbnails (150 · 300 · 600w)</option>
                            <option value="social">Social (640 · 1080 · 1920w)</option>
                            <option value="hero">Hero (800 · 1200 · 1920 · 2560w)</option>
                        </select>
                    </div>
                </details>

                <details class="fancy">
                    <summary>Transformations</summary>
                    <div class="body">
                        <div class="two-col">
                            <div>
                                <label>Rotate</label>
                                <select id="rotate">
                                    <option value="0">None</option>
                                    <option value="90">90° CW</option>
                                    <option value="180">180°</option>
                                    <option value="270">90° CCW</option>
                                </select>
                            </div>
                            <div>
                                <label>Flip</label>
                                <select id="flip">
                                    <option value="">None</option>
                                    <option value="horizontal">Horizontal</option>
                                    <option value="vertical">Vertical</option>
                                    <option value="both">Both</option>
                                </select>
                            </div>
                        </div>

                        <label style="margin-top:12px">Effects</label>
                        <div class="row">
                            <label><input type="checkbox" class="effect-cb" value="grayscale"> Grayscale</label>
                            <label><input type="checkbox" class="effect-cb" value="sepia"> Sepia</label>
                            <label><input type="checkbox" class="effect-cb" value="blur"> Blur</label>
                            <label><input type="checkbox" class="effect-cb" value="sharpen"> Sharpen</label>
                        </div>

                        <div class="two-col" style="margin-top:12px">
                            <div>
                                <label>Brightness <span class="slider-val" id="brightness-val">0</span></label>
                                <input type="range" id="brightness" min="-100" max="100" value="0">
                            </div>
                            <div>
                                <label>Contrast <span class="slider-val" id="contrast-val">0</span></label>
                                <input type="range" id="contrast" min="-100" max="100" value="0">
                            </div>
                        </div>
                    </div>
                </details>

                <details class="fancy">
                    <summary>Watermark</summary>
                    <div class="body">
                        <label>Text</label>
                        <input type="text" id="watermark_text" placeholder="© The Castle Fun Center">
                        <div class="two-col" style="margin-top:8px">
                            <div>
                                <label>Position</label>
                                <select id="watermark_position">
                                    <option value="br">Bottom right</option>
                                    <option value="bl">Bottom left</option>
                                    <option value="tr">Top right</option>
                                    <option value="tl">Top left</option>
                                    <option value="center">Center</option>
                                </select>
                            </div>
                            <div>
                                <label>Opacity <span class="slider-val" id="watermark_opacity-val">50</span></label>
                                <input type="range" id="watermark_opacity" min="0" max="100" value="50">
                            </div>
                        </div>
                    </div>
                </details>

                <details class="fancy">
                    <summary>Advanced</summary>
                    <div class="body">
                        <label><input type="checkbox" id="strip_exif" checked> Strip EXIF metadata</label>

                        <label style="margin-top:10px">Smart compress — target size (KB)</label>
                        <input type="number" id="smart_target_kb" min="0" step="1" placeholder="0 = off">
                        <div class="hint">When non-zero, quality is binary-searched to hit the target. JPEG/WebP/AVIF only.</div>

                        <label style="margin-top:10px">Naming pattern</label>
                        <input type="text" id="naming_pattern" value="{name}-{hash}">
                        <div class="hint mono">Tokens: {name} {hash} {width} {height} {format}</div>
                    </div>
                </details>

                <div class="action-bar">
                    <div class="left">
                        <button class="btn btn-ghost btn-sm" id="clear-btn" type="button">Clear queue</button>
                    </div>
                    <div class="right">
                        <button class="btn btn-gold" id="convert-btn" type="button" disabled>Convert now</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="results-block" id="results"></div>
    </section>

    <!-- ============ PDF TAB ============ -->
    <section class="tab-section" id="tab-pdf" data-tab="pdf">
        <div class="card card-hairline">
            <h2>PDF tools</h2>
            <p class="muted">Combine images into a single PDF, or extract PDF pages back into images.</p>

            <div class="pdf-mode-switch" role="tablist">
                <button class="active" data-mode="image-to-pdf" type="button">Image → PDF</button>
                <button data-mode="pdf-to-image" type="button" <?= $canPdfIn ? '' : 'disabled title="Requires Imagick + Ghostscript on the server"' ?>>PDF → Image</button>
            </div>

            <!-- Image → PDF panel -->
            <div id="pdf-image-to-pdf">
                <div class="dropzone" id="pdf-images-drop" style="margin-bottom:18px">
                    <h2>Drop images to combine</h2>
                    <p>They'll become pages in the order listed below. Drag to reorder.</p>
                    <div class="alt-actions">
                        <button class="btn btn-gold" id="pdf-pick-images-btn" type="button">Choose images</button>
                    </div>
                    <input type="file" id="pdf-images-picker" multiple accept="image/*" class="sr-only">
                </div>

                <div class="two-col">
                    <div class="card">
                        <h3>Page options</h3>
                        <label>Page size</label>
                        <select id="pdf-page-size">
                            <option value="auto">Auto (match image)</option>
                            <option value="a4">A4</option>
                            <option value="letter">US Letter</option>
                            <option value="legal">US Legal</option>
                        </select>
                        <div class="two-col" style="margin-top:8px">
                            <div>
                                <label>Orientation</label>
                                <select id="pdf-orientation">
                                    <option value="auto">Auto</option>
                                    <option value="portrait">Portrait</option>
                                    <option value="landscape">Landscape</option>
                                </select>
                            </div>
                            <div>
                                <label>Margin</label>
                                <select id="pdf-margin">
                                    <option value="none">None</option>
                                    <option value="narrow" selected>Narrow</option>
                                    <option value="normal">Normal</option>
                                </select>
                            </div>
                        </div>
                        <label style="margin-top:8px">PDF title</label>
                        <input type="text" id="pdf-title" value="The Castle Fun Center">
                        <button class="btn btn-gold" id="make-pdf-btn" type="button" disabled style="margin-top:16px;width:100%">
                            Build PDF
                        </button>
                    </div>

                    <div class="card">
                        <h3>Pages</h3>
                        <div class="queue" id="pdf-image-list"></div>
                    </div>
                </div>

                <div class="results-block" id="pdf-result"></div>
            </div>

            <!-- PDF → Image panel -->
            <div id="pdf-to-image" class="hidden">
                <?php if (!$canPdfIn): ?>
                    <div class="card" style="text-align:center">
                        <h3 style="color:var(--castle-gold-hi)">Not available on this host</h3>
                        <p>PDF rasterization requires the <code>imagick</code> PHP extension with a working Ghostscript delegate. Ask your hosting provider to install them, then revisit this page.</p>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="row">
                            <button class="btn btn-gold" id="pdf-in-btn" type="button">Choose a PDF</button>
                            <span id="pdf-page-count" class="muted"></span>
                        </div>
                        <input type="file" id="pdf-in-picker" accept="application/pdf,.pdf" class="sr-only">

                        <div id="pdf-extract-controls" class="hidden" style="margin-top:18px">
                            <div class="two-col">
                                <div>
                                    <label>Output format</label>
                                    <select id="pdf-out-format">
                                        <option value="png">PNG (lossless)</option>
                                        <option value="jpeg">JPEG (smallest)</option>
                                        <option value="webp">WebP</option>
                                    </select>
                                </div>
                                <div>
                                    <label>DPI</label>
                                    <select id="pdf-dpi">
                                        <option value="72">72 (web)</option>
                                        <option value="150" selected>150 (print-ready)</option>
                                        <option value="300">300 (high res)</option>
                                    </select>
                                </div>
                            </div>
                            <label style="margin-top:8px">JPEG/WebP quality</label>
                            <input type="range" id="pdf-out-quality" min="50" max="100" value="88">

                            <p class="hint">Click page thumbnails to select. Leave none selected to extract all pages.</p>
                            <div class="pdf-pages" id="pdf-page-grid"></div>

                            <button class="btn btn-gold" id="pdf-extract-btn" type="button" style="margin-top:16px">
                                Extract pages
                            </button>
                        </div>
                    </div>
                    <div class="results-block">
                        <div class="result-outputs" id="pdf-extract-result"></div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </section>

    <!-- ============ SERVER TAB ============ -->
    <section class="tab-section" id="tab-server" data-tab="server">
        <div class="card card-hairline">
            <h2>Server capabilities</h2>
            <p class="muted">Castle detects what's available on this host and adapts automatically. Optional rows unlock extra formats when enabled.</p>

            <div id="cap-summary" class="row" style="margin-bottom:16px"></div>

            <div style="overflow-x:auto">
                <table class="cap-table" id="cap-table">
                    <thead>
                        <tr><th>Capability</th><th>Value</th><th>Status</th></tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script src="assets/js/utils.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
