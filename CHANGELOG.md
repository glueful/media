# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

### Added

- **Discovery-path regression test.** Loads the provider through the framework's real
  extension-discovery dispatch (`defs()` pass-through, else `services()` via the DSL loader),
  guarding against typed `Definition` objects being returned from `services()` — a regression the
  existing `Container::load()`-based tests cannot catch.

### Fixed

- **Boot compatibility with framework 1.55 — framework pin raised to `^1.55.0`.** The service
  provider declared its bindings via the DSL `services()` method but returned strongly-typed
  `DefinitionInterface` objects, which the framework's DSL service loader rejects
  (`"Service '<id>' must be an array"`). Under framework 1.55 this threw during boot in dev/test
  and silently dropped the bindings in production (so `image()` and the
  `MediaProcessorInterface` seam were never registered). The method is now `defs()`, the
  strongly-typed pass-through path that accepts `DefinitionInterface` objects. The `defs()`
  dispatch was introduced in framework v1.55.0 exactly (absent in 1.52–1.54, where this provider
  would register nothing at all), so the framework requirement rises from `>=1.52.0` to
  `>=1.55.0` (composer `require-dev` and `extra.glueful.requires`).

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
