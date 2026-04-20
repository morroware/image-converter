# The Castle Fun Center — Image Toolkit

A self-hosted image conversion suite built on vanilla PHP + HTML + CSS + JS
— no Composer, npm, or databases required. Designed to drop straight into a
shared cPanel hosting account.

## What it does

- Converts images between **JPEG, PNG, GIF, WebP, AVIF, BMP, TIFF, HEIC, SVG,
  PSD, ICO** (exact list depends on the PHP extensions installed — see the
  **Server** tab inside the app).
- **Image → PDF** — combine any number of images into a single PDF. Page
  size, orientation, margins, and page order are all configurable.
- **PDF → Image** — extract pages from a PDF at 72 / 150 / 300 DPI. Requires
  Imagick + Ghostscript on the server; gracefully hidden when unavailable.
- **Responsive sizes** — generate multiple widths in one pass (`srcset`-ready).
- **Transformations** — rotate, flip, crop, grayscale, sepia, blur, sharpen,
  brightness, contrast, text watermark.
- **Smart compress** — binary-searches quality to hit a target KB size.
- **Favicon & OG image helpers.**
- **URL import** + **clipboard paste** for fast ingest.
- **Gallery** with search, filter, sort, multi-select, ZIP download, delete.
- **Optional password gate** for private deployments.

## Install on cPanel (shared hosting)

1. **Upload the folder.**
   Drag the whole `image-converter/` folder into `public_html/` (or any
   subfolder) via cPanel File Manager or FTP.

2. **Pick PHP 7.4 or newer.** (PHP 8.1+ unlocks AVIF output.)
   cPanel → *MultiPHP Manager* → set your domain to PHP 8.1 or later.

3. **Enable PHP extensions** (cPanel → *Select PHP Version* → *Extensions*):
   - **`gd`** — required.
   - **`fileinfo`** — recommended for robust MIME detection.
   - **`zip`** — recommended for bulk-download.
   - **`exif`** — optional, used to detect/strip EXIF.
   - **`imagick`** — **optional but highly recommended** — unlocks TIFF,
     HEIC, SVG, PSD input and PDF rasterization. If your host doesn't offer
     it, the app still runs — those features just stay hidden.

4. **Set directory permissions:**
   ```
   chmod 755 image-converter
   chmod 775 image-converter/optimized
   ```

5. **Copy the config:**
   ```
   cp config.example.php config.php
   ```

6. **Optional — turn on the password gate.** Open a terminal (or use cPanel
   *Terminal*) and generate a password hash:
   ```
   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   Then edit `config.php`:
   ```
   define('CASTLE_AUTH_ENABLED', true);
   define('CASTLE_AUTH_HASH', '$2y$10$…paste the hash here…');
   ```

7. **Visit the site** — open
   `https://yourdomain.com/image-converter/` and the app will be live.
   Click the **Server** tab to verify all green checkmarks on your host.

## Upgrading `upload_max_filesize` on cPanel

If you need to accept uploads larger than 25 MB, raise both
`upload_max_filesize` and `post_max_size` in cPanel's *MultiPHP INI Editor*.
The app respects whichever is lower.

## File layout

```
image-converter/
├── index.php                 Convert / PDF / Server page
├── gallery.php               File browser
├── api.php                   JSON endpoint for all AJAX actions
├── login.php / logout.php    Used only when auth is enabled
├── bootstrap.php             Config + session + autoloader
├── config.php                Your settings (copied from config.example.php)
├── .htaccess                 Security headers + caching
├── lib/                      Backend classes
│   ├── ImageOptimizer.php    GD pipeline (+ Imagick fallback)
│   ├── FormatHandler.php     Capability detection
│   ├── PdfWriter.php         Pure-PHP image → PDF
│   ├── PdfRasterizer.php     Imagick-based PDF → image
│   ├── Uploader.php          Upload validation
│   ├── RateLimiter.php       Per-IP flat-file rate limiter
│   ├── Auth.php              Optional password gate
│   └── Response.php          JSON + CSRF helpers
├── assets/
│   ├── css/ (theme.css, app.css, gallery.css)
│   ├── js/  (utils.js, app.js, gallery.js)
│   └── img/castle-crest.svg
└── optimized/                Writable output directory
```

## Running locally (for testing)

```
php -S localhost:8080 -t image-converter
```

Then open `http://localhost:8080/`.

## Security notes

- All POST endpoints are CSRF-protected with a per-session token.
- Rate limiting (default 60 req/min per IP, configurable) throttles abuse.
- `/lib` and `/config.php` are blocked at the web layer by `.htaccess`.
- Uploaded filenames are sanitized; suspicious double-extensions are rejected.
- URL import refuses private-IP targets (simple SSRF guard).
- Script execution is disabled inside `/optimized`.

## License

Code: MIT. Castle crest artwork: generated for this project, public domain.
