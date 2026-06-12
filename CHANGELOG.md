# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

### Documentation

- **README and ImageProcessorIntegration aligned with actual behavior.** Corrected the HTTP
  output method (`stream()`, not the nonexistent `toResponse()`); documented `cached()`'s
  read-or-populate + tag-invalidation semantics and the auto-key collision caveat; added the
  `formats`/`security`/`metadata`/`watermark` env vars (marking `IMAGE_PNG_COMPRESSION` inert and
  `IMAGE_MAX_MEMORY`/`IMAGE_PROCESSING_TIMEOUT` reserved/not yet enforced); documented the
  fail-closed remote-fetch, SSRF, watermark, and getID3 hardening; raised the documented
  framework requirement to >=1.55.0.

### Added

- **Discovery-path regression test.** Loads the provider through the framework's real
  extension-discovery dispatch (`defs()` pass-through, else `services()` via the DSL loader),
  guarding against typed `Definition` objects being returned from `services()` — a regression the
  existing `Container::load()`-based tests cannot catch.

### Changed

- **Remote image fetching is now opt-in (fail-closed defaults).** `config/image.php` defaults
  `security.allowed_domains` to an empty list and `security.disable_external_urls` to `true`, so no
  external host is fetched unless explicitly allow-listed via `IMAGE_ALLOWED_DOMAINS` (with
  `IMAGE_DISABLE_EXTERNAL_URLS=false`). The previous defaults (`['*']` / `false`) were fail-open.

### Security

- **SSRF hardening for remote image fetching.** `ImageProcessor::fromUrl()` previously validated
  only the initial URL and then let `file_get_contents` follow redirects, so an allow-listed or
  open-redirect host could 302 to an internal address (e.g. cloud metadata `169.254.169.254`)
  that was fetched unchecked; the URL blocklist was also substring-based and missed
  encoded / IPv6 / link-local forms. Redirects are now followed manually with **every hop
  re-validated**, and each target host is **resolved and rejected when it maps to a
  private / loopback / link-local / reserved IP** (`isDisallowedIp`, replacing substring
  blocklisting). Three review gaps in the original pass are closed too: the security-critical
  stream-context options (`follow_location`/`max_redirects`/`ignore_errors`) are forced after the
  caller-options merge so they can never be overridden; downloads are **size-capped** (new
  `security.max_file_size`, env `IMAGE_REMOTE_MAX_FILESIZE`, default `10M`) via a length-capped
  read plus a Content-Length pre-check, preventing unbounded buffering; and **`make()` — and
  therefore the `image()` helper — now routes http(s) URLs through the same hardened per-hop
  fetch** instead of a validate-once-then-decode flow that was open to DNS-rebinding TOCTOU.
  Covered by `RemoteFetchSafetyTest` (IP classification incl. decimal/hex/octal literals,
  redirect resolution, option forcing, size caps, and the `make()` routing). Note: the core
  `ImageSecurityValidator`'s fail-open defaults remain a separate cross-repo hardening item.
- **Watermark sources are confined to the configured directory.** `watermark()` handed its path
  straight to the decoder, which reads any local path or stream-wrapper string
  (`/etc/passwd`, `php://filter/...`, `http://...`) — an arbitrary-file-read/SSRF vector.
  Watermark paths now reject URL/stream schemes outright and must canonicalize (realpath) inside
  `paths.watermark_dir`, with sibling-prefix-safe matching. Also fixed: centering a watermark
  with an odd dimension difference threw a `TypeError` (float coords into int parameters), and
  unknown-size uploads (PSR-7 `getSize(): null`) now skip the file-size cap instead of asserting
  a 0-byte size — decode-time dimension/integrity checks still apply.
- **getID3 analysis is capped and contained.** Media metadata extraction passed uploads straight
  to `getID3::analyze()` — a pure-PHP container parser with a history of vulnerabilities on
  malformed input — with no size cap and no warning containment. Files are now size-capped before
  analysis (`metadata.max_filesize`, env `MEDIA_METADATA_MAX_FILESIZE`, default `500M` — an abuse
  guard, not a typical-use limit), the parser runs with warnings/Throwables contained, and
  dimension/string values from container metadata are sanitized (control characters stripped,
  length-capped). Oversized or malformed uploads degrade to type-only metadata instead of
  exhausting a worker.

### Fixed

- **`cached()` actually caches now.** It was write-only — every "cached" pipeline re-decoded and
  re-encoded while filling entries nothing ever read. It now reads first and, on a hit, restores
  the cached image without re-encoding; caller-supplied keys with unsafe characters are hashed
  (closing Memcached delimiter-injection / file-cache traversal via raw keys), and the configured
  `cache.tags` are associated via the CacheStore tag API so grouped invalidation works. Note:
  auto-generated keys hash operations + dimensions but not source bytes — pass an explicit key
  when two sources could share both.
- **Per-format encoder config takes effect.** `IMAGE_JPEG_PROGRESSIVE` (progressive JPEG) and
  `IMAGE_WEBP_LOSSLESS` (expressed as quality 100 — Intervention v4's only lossless WebP path)
  were documented but ignored by the encoder; they're now wired through both the factory and DI
  config paths. `IMAGE_PNG_COMPRESSION` remains inert — Intervention v4's PNG encoder has no
  compression parameter — and is now documented as such instead of silently dropped.
- **ImageProcessor factories no longer share one mutated instance.** `make()`/`fromUrl()`/
  `fromUpload()`/`create()` all resolved the container's shared singleton, swapped its image
  state, and returned it — process two images in one request and the first reference silently
  pointed at the second image's bytes; under persistent workers (RoadRunner/Swoole) state bled
  across requests. Each factory call (and each `ImageProcessorInterface` container resolution —
  the binding is now non-shared) yields a fresh processor. The `image()` helper also forwards its
  explicit `$context` argument instead of discarding it in favor of the static default.
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
