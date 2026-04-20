<?php
/**
 * The Castle Fun Center — Image Toolkit
 * Gallery page (browse / search / bulk-download / delete).
 */

require_once __DIR__ . '/bootstrap.php';

$csrf    = \Castle\Response::csrfToken();
$brand   = CASTLE_BRAND_NAME;
$tagline = CASTLE_BRAND_TAGLINE;
$authEnabled = \Castle\Auth::isEnabled();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="castle-csrf" content="<?= $h($csrf) ?>">
<title><?= $h($brand) ?> — Gallery</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/css/theme.css">
<link rel="stylesheet" href="assets/css/app.css">
<link rel="stylesheet" href="assets/css/gallery.css">
</head>
<body>

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
            <a href="index.php">Convert</a>
            <a href="index.php#pdf">PDF</a>
            <a href="gallery.php" class="active">Gallery</a>
            <a href="index.php#server">Server</a>
            <?php if ($authEnabled): ?>
                <a href="logout.php" class="logout">Sign out</a>
            <?php endif; ?>
        </nav>
    </header>

    <div class="card card-hairline">
        <div class="gallery-toolbar">
            <input type="text" id="gallery-search" placeholder="Search filenames…">
            <select id="gallery-format">
                <option value="all">All formats</option>
                <option value="webp">WebP</option>
                <option value="avif">AVIF</option>
                <option value="jpg">JPG</option>
                <option value="jpeg">JPEG</option>
                <option value="png">PNG</option>
                <option value="gif">GIF</option>
                <option value="bmp">BMP</option>
                <option value="pdf">PDF</option>
                <option value="tiff">TIFF</option>
                <option value="ico">ICO</option>
            </select>
            <select id="gallery-sort">
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="largest">Largest first</option>
                <option value="smallest">Smallest first</option>
                <option value="name">Name A&rarr;Z</option>
            </select>
            <a href="index.php" class="btn btn-gold btn-sm">+ New</a>
        </div>

        <div class="gallery-stats" id="gallery-stats"></div>

        <div class="gallery-grid" id="gallery-grid"></div>

        <div class="bulk-bar" id="bulk-bar">
            <div>
                <strong><span id="bulk-count">0</span> selected</strong>
            </div>
            <div class="row">
                <button class="btn btn-ghost btn-sm" id="bulk-clear" type="button">Clear</button>
                <button class="btn btn-gold btn-sm" id="bulk-download" type="button">Download ZIP</button>
                <button class="btn btn-danger btn-sm" id="bulk-delete" type="button">Delete</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/utils.js"></script>
<script src="assets/js/gallery.js"></script>
</body>
</html>
