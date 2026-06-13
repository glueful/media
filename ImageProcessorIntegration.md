# Image Processor Integration with Glueful (glueful/media)

The `glueful/media` extension provides rich media processing (image transforms,
thumbnails, media metadata) for Glueful applications. Install it with:

```bash
composer require glueful/media
```

The extension registers its services through `Glueful\Extensions\Media\MediaServiceProvider`
and integrates with the framework's container, cache, exceptions, logging, and HTTP
layers exactly like a native service.

## Framework Pattern Integration

### 1. **Dependency Injection Container Integration**

```php
use Glueful\Extensions\Media\Contracts\ImageProcessorInterface;
use Glueful\Extensions\Media\ImageProcessor;

// The MediaServiceProvider registers:
// - Intervention\Image\ImageManager (GD or Imagick driver from config/image.php)
// - ImageProcessorInterface => ImageProcessor
// - The security validator and supporting services
```

### 2. **Cache Integration**

`cached(?string $key = null, int $ttl = 3600)` is read-or-populate: it first looks up the
entry and, on a **hit**, restores the cached image (decodes the stored bytes) and returns
without re-encoding; on a **miss** it encodes once, persists the entry under the configured
`image.cache.prefix`, and associates the configured `image.cache.tags` via the CacheStore
`addTags()` API so grouped invalidation works (drivers that cannot tag, e.g. plain file,
no-op). Read or write failures are logged and swallowed — caching never aborts the pipeline.

```php
// Hit: restored from cache, no re-encode. Miss: encoded once, then cached.
image($context, '/path/to/image.jpg')
    ->resize(800, 600)
    ->cached('hero-800x600', 86400)   // explicit, stable key
    ->stream();
```

Caller-supplied keys are sanitized: any key with a character outside `[A-Za-z0-9_.-]` is
replaced wholesale with a short sha256 (closing Memcached delimiter injection and file-cache
path traversal via raw keys). When no key is given, an auto key is derived from the
operations + dimensions — but **not** the source bytes, so two distinct sources with the same
dimensions and operations collide. Pass an explicit key whenever that is possible.

The configured tags default to `['images', 'processed']` (`config/image.php`). Invalidate a
group with the framework cache's tag API, e.g. `$cache->invalidateTags(['images'])`.

### 3. **Exception Handling Integration**

```php
// Uses the framework exception hierarchy
use Glueful\Http\Exceptions\Domain\BusinessLogicException;

public function processImage(): void
{
    try {
        // Process image
    } catch (\Exception $e) {
        throw BusinessLogicException::operationNotAllowed(
            'image_processing',
            'Failed to process image: ' . $e->getMessage()
        );
    }
}
```

### 4. **Configuration Integration**

```php
// Integrates with the config() helper (config/image.php ships with the extension)
$driver = config($context, 'image.driver', 'gd');
$maxWidth = config($context, 'image.limits.max_width', 2048);
$cacheEnabled = config($context, 'image.cache.enabled', true);

// Environment variable support
IMAGE_DRIVER=gd
IMAGE_MAX_WIDTH=2048
IMAGE_CACHE_ENABLED=true
```

### 5. **Logging Integration**

```php
use Psr\Log\LoggerInterface;

class ImageProcessor
{
    private LoggerInterface $logger;

    private function logProcessingTime(float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->logger->info('Image processed', [
            'duration_ms' => round($duration * 1000, 2),
            'type' => 'image_processing'
        ]);
    }
}
```

### 6. **HTTP Output Integration**

The processor streams encoded bytes directly to the output buffer via `stream()`, which
emits the image headers (with caller-supplied overrides merged in) and echoes the body:

```php
// public function stream(array $headers = []): void
$processor = image($context, '/path/to/image.jpg')->resize(800, 600);
$processor->stream([
    'Cache-Control' => 'public, max-age=86400',
]);
// Sends Content-Type / Content-Length / Cache-Control headers, then echoes the image.
```

`stream()` returns `void` — it writes straight to PHP's output, so call it from a route
that does not also return a framework `Response`. To build a `Response` instead, take the
encoded bytes from `getImageData()` and wrap them yourself:

```php
use Glueful\Http\Response;

$bytes = image($context, '/path/to/image.jpg')->resize(800, 600)->getImageData();
return Response::create($bytes, 200, ['Content-Type' => 'image/jpeg']);
```

### 7. **Helper Function Integration**

The extension ships an `image()` helper (registered via `autoload.files`):

```php
// helpers.php
if (!function_exists('image')) {
    function image(
        \Glueful\Bootstrap\ApplicationContext $context,
        string $source
    ): \Glueful\Extensions\Media\Contracts\ImageProcessorInterface {
        $processor = app($context, \Glueful\Extensions\Media\Contracts\ImageProcessorInterface::class);
        return $processor::make($source);
    }
}

// Usage
$processed = image($context, '/path/to/image.jpg')
    ->resize(800, 600)
    ->quality(85)
    ->cached()
    ->save('/path/to/output.jpg');
```

## Key Integration Points

1. **Container Bindings**: Registered by `MediaServiceProvider` like other services
2. **Configuration**: Uses the `config()` helper and `.env` variables (config/image.php)
3. **Caching**: Read-or-populate `cached()` over the existing cache system, with tag-based invalidation
4. **Exceptions**: Uses the framework exception hierarchy
5. **Logging**: Uses the PSR-3 logger from the container
6. **HTTP**: Streams encoded bytes to output via `stream()`, or hand `getImageData()` to a `Response`
7. **Helper Functions**: Follows the framework helper pattern (`image()`)
8. **Service Providers**: Registered like other Glueful extensions

This ensures the media processor feels native to the Glueful framework while keeping
the heavy image/metadata dependencies isolated in the `glueful/media` extension.
