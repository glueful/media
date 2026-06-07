# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [1.0.0] - 2026-06-07 — Initial release (extracted from Glueful framework 1.52.0)

Rich media processing, extracted from framework core in **Glueful framework 1.52.0**.
Requires `glueful/framework >=1.52.0`, which removed these classes/deps from core and
introduced the `Glueful\Uploader\Contracts\MediaProcessorInterface` seam this extension binds.

### Added

- **`MediaProcessor`** implementing the core `Glueful\Uploader\Contracts\MediaProcessorInterface`
  seam — metadata extraction, thumbnail generation (written through the caller's
  `StorageInterface`, never a self-constructed one), on-demand variant rendering, and a
  thumbnail-support MIME check. `MediaServiceProvider` binds it (last-provider-wins over the
  core no-op default), so the core `FileUploader` / `UploadController` resolve it optionally.
- **`ImageProcessor`** (fluent, Intervention Image v4) implementing
  `Glueful\Extensions\Media\Contracts\ImageProcessorInterface`, plus `ThumbnailGenerator` and
  `MediaMetadataExtractor` (getID3) — moved from core with their namespaces remapped.
- **`image()` global helper**, re-authored here and autoloaded via `autoload.files`.
- **`config/image.php`** (the `image` config key + `IMAGE_*` env), merged via the provider's
  `register()`; the runtime deps `intervention/image ^4.1` and `james-heinrich/getid3 ^1.9`.

### Migration from framework core

Namespace map for any app/extension code referencing the moved classes:

```
Glueful\Services\ImageProcessor            →  Glueful\Extensions\Media\ImageProcessor
Glueful\Services\ImageProcessorInterface   →  Glueful\Extensions\Media\Contracts\ImageProcessorInterface
Glueful\Uploader\ThumbnailGenerator        →  Glueful\Extensions\Media\ThumbnailGenerator
Glueful\Uploader\MediaMetadataExtractor    →  Glueful\Extensions\Media\MediaMetadataExtractor
```

`Glueful\Uploader\MediaMetadata` is unchanged and stays in core. The core
`Glueful\Services\ImageSecurityValidator` also stays in core (bound by `StorageProvider`);
this extension resolves it from there rather than re-binding it.
