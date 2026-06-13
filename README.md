# Media Extension for Glueful

## Overview

Media adds rich media processing to your Glueful application: transform images
(resize, crop, fit, format conversion, watermarking), generate thumbnails on
upload, serve on-demand image variants from the blob endpoint, and extract media
metadata (dimensions, duration, codecs).

It plugs into the framework's upload pipeline through the
`Glueful\Uploader\Contracts\MediaProcessorInterface` seam: once installed, file
uploads gain thumbnails and full metadata and the blob endpoint serves
resized/converted images. The framework keeps working without it — see
[Behavior Without the Extension](#behavior-without-the-extension).

## Features

- **Fluent image processing**: resize, fit, crop, quality, format conversion, and watermarking via Intervention Image v4
- **On-demand variants**: serve resized/converted images straight from the blob endpoint (`?width=&height=&quality=&format=`)
- **Thumbnail generation**: automatic thumbnails on `uploadMedia()`, written to the upload's own storage disk
- **Media metadata**: dimensions, duration, and codec details via getID3
- **`image()` helper**: a fluent global helper for ad-hoc image work
- **Seam-based**: binds the core `MediaProcessorInterface`; the framework degrades gracefully when the extension is absent
- **Configurable**: driver (GD/Imagick), processing limits, quality, security, and caching via the `image` config + `IMAGE_*` env

## Installation

### Installation (Recommended)

**Install via Composer**

```bash
composer require glueful/media

# Rebuild the extensions cache after adding new packages
php glueful extensions:cache
```

Composer discovers packages of type `glueful-extension`, but **installing does not auto-enable** them — the provider must be added to `config/extensions.php`'s `enabled` allow-list. The CLI does that for you:

```bash
# Enable (adds the provider FQCN to config/extensions.php + recompiles the cache)
php glueful extensions:enable media

# Disable (removes it)
php glueful extensions:disable media
```

In production, manage the `enabled` list in config and run `php glueful extensions:cache` in your deploy step. The extension ships no migrations.

### Local Development Installation

To develop the extension locally, register it as a Composer **path repository** in your app's `composer.json`, then require and enable it:

```jsonc
// composer.json
"repositories": [
    { "type": "path", "url": "extensions/media", "options": { "symlink": true } }
]
```

```bash
composer require glueful/media:@dev
php glueful extensions:enable media
```

Entries in `config/extensions.php` are plain string FQCNs (no `::class`) — prefer `extensions:enable` over editing by hand.

### Verify Installation

```bash
php glueful extensions:list
php glueful extensions:info media
php glueful extensions:diagnose
```

Post-install checklist:

- Ensure a PHP image driver is available: **GD** (default) or **ImageMagick** (`IMAGE_DRIVER=imagick`)
- Rebuild cache after Composer operations: `php glueful extensions:cache`
- Confirm the seam is bound: resolving `Glueful\Uploader\Contracts\MediaProcessorInterface` yields `Glueful\Extensions\Media\MediaProcessor`

## Configuration

Configuration is loaded from the extension's `config/image.php` and merged under the `image` key by the service provider. It reads the standard `IMAGE_*` environment variables:

```env
# Driver selection (gd|imagick)
IMAGE_DRIVER=gd

# Processing limits
IMAGE_MAX_WIDTH=2048
IMAGE_MAX_HEIGHT=2048
IMAGE_MAX_FILESIZE=10M             # max input file size for local processing
# Reserved / NOT YET ENFORCED — present in config but no code applies them:
IMAGE_MAX_MEMORY=256M              # reserved (no runtime memory cap is applied)
IMAGE_PROCESSING_TIMEOUT=30       # reserved (no per-operation timeout is applied)

# Quality
IMAGE_JPEG_QUALITY=85
IMAGE_WEBP_QUALITY=80

# Per-format encoder settings
IMAGE_JPEG_PROGRESSIVE=false      # functional: emits progressive JPEG when true
IMAGE_WEBP_LOSSLESS=false         # functional: lossless WebP (encoded as quality 100)
IMAGE_PNG_COMPRESSION=6           # INERT: Intervention v4's PNG encoder has no
                                  #        compression parameter; kept for config parity

# Security
IMAGE_DISABLE_EXTERNAL_URLS=true  # default true — remote fetching is OFF unless flipped
IMAGE_ALLOWED_DOMAINS=            # empty default — comma-separated allow-list of hosts
IMAGE_VALIDATE_MIME=true
IMAGE_REMOTE_MAX_FILESIZE=10M     # hard cap on a remotely fetched image body

# Metadata (getID3)
MEDIA_METADATA_MAX_FILESIZE=500M  # abuse guard; larger files degrade to type-only metadata

# Caching
IMAGE_CACHE_ENABLED=true
IMAGE_CACHE_TTL=86400

# Paths
IMAGE_WATERMARK_DIR=              # confinement base for watermark() sources (see Security)
```

The framework's own upload keys — `UPLOADS_IMAGE_PROCESSING` / `UPLOADS_THUMBNAILS` / `THUMBNAIL_*` (in the app's `config/uploads.php` / `config/filesystem.php`) — gate the upload pipeline and take effect once this extension binds the seam.

### Security behavior

The extension fails closed and confines its filesystem and network reach:

- **Remote fetching is opt-in.** `IMAGE_DISABLE_EXTERNAL_URLS` defaults to `true` and
  `IMAGE_ALLOWED_DOMAINS` defaults to empty, so no external host is fetched unless you set
  `IMAGE_DISABLE_EXTERNAL_URLS=false` and allow-list hosts.
- **SSRF-hardened fetches.** Redirects are followed manually with **every hop re-validated**,
  and each target host is rejected when it resolves to a private/loopback/link-local/reserved
  IP. The download is **size-capped** by `IMAGE_REMOTE_MAX_FILESIZE` (default `10M`).
- **Watermark sources are confined.** `watermark()` rejects URL/stream-wrapper paths and
  requires the canonical path to sit inside `paths.watermark_dir` (`IMAGE_WATERMARK_DIR`,
  falling back to the system temp dir when unset).
- **getID3 is capped and contained.** Metadata extraction is size-capped by
  `MEDIA_METADATA_MAX_FILESIZE` (default `500M`) and runs with parser warnings/throwables
  contained; oversized or malformed files degrade to type-only metadata.
- **Fresh processor instances.** Each factory call / container resolution yields a new
  processor (the binding is non-shared). Persistent-worker users (RoadRunner/Swoole) get no
  cross-request state bleed.

## Usage

### Fluent image processing

```php
// Resize and re-encode to disk
image($context, '/path/to/image.jpg')
    ->resize(800, 600)
    ->quality(85)
    ->save('/path/to/output.jpg');

// Convert format and get the encoded bytes (e.g. to stream a response)
$webp = image($context, '/path/to/image.png')
    ->resize(1200, null)   // width 1200, height auto (aspect preserved)
    ->format('webp')
    ->getImageData();

// Apply a watermark, then save
image($context, '/path/to/photo.jpg')
    ->watermark('/path/to/logo.png', 'bottom-right')
    ->save('/path/to/watermarked.jpg');
```

### Thumbnails + full metadata on upload

`FileUploader::uploadMedia()` writes a thumbnail onto the upload's own storage disk and returns full metadata:

```php
$result = $uploader->uploadMedia($file, 'media/images');
$result['thumb_url'];                   // thumbnail URL
$result['width'];  $result['height'];   // populated dimensions
```

### On-demand variants

The core blob endpoint routes through this extension's `renderVariant()`:

```
GET /blobs/{uuid}?width=300&height=200&quality=80&format=webp
```

## Behavior Without the Extension

The framework degrades gracefully when no media processor is bound:

- `image()` is **undefined** (function-not-found, not a stub);
- `uploadMedia()` succeeds but returns `thumb_url: null` and **type-only** metadata (MIME classification, no dimensions/duration);
- the blob-resize endpoint serves the **original** image (`415` only on an explicit format conversion).

## Requirements

- PHP 8.3 or higher
- Glueful 1.55.0 or higher (the provider registers via the `defs()` typed-definition path
  introduced in framework 1.55.0; on 1.52–1.54 it registers nothing)
- GD or ImageMagick PHP extension (for image processing)
- `intervention/image ^4.1` and `james-heinrich/getid3 ^1.9` (installed automatically)

## License

MIT — licensed consistently with the Glueful framework.

## Support

For issues, feature requests, or questions, please create an issue in the repository.
