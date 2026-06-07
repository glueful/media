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

```php
// Uses the existing Glueful cache system
use Glueful\Extensions\Media\ImageProcessor;

class ImageProcessor implements ImageProcessorInterface
{
    public function cached(?string $key = null, int $ttl = 3600): self
    {
        $cacheKey = $key ?? $this->generateCacheKey();

        // Use existing framework cache with the configured prefix/tags
        $this->cache->set(
            config($context, 'image.cache.prefix') . $cacheKey,
            $this->getImageData(),
            $ttl
        );

        return $this;
    }
}
```

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

### 6. **HTTP Response Integration**

```php
use Glueful\Http\Response;

public function toResponse(array $headers = []): Response
{
    $imageData = $this->getImageData();
    $mimeType = $this->getMimeType();

    return Response::create($imageData, 200, array_merge([
        'Content-Type' => $mimeType,
        'Content-Length' => strlen($imageData),
        'Cache-Control' => 'public, max-age=3600',
    ], $headers));
}
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
3. **Caching**: Integrates with the existing cache system
4. **Exceptions**: Uses the framework exception hierarchy
5. **Logging**: Uses the PSR-3 logger from the container
6. **HTTP**: Returns proper `Response` objects
7. **Helper Functions**: Follows the framework helper pattern (`image()`)
8. **Service Providers**: Registered like other Glueful extensions

This ensures the media processor feels native to the Glueful framework while keeping
the heavy image/metadata dependencies isolated in the `glueful/media` extension.
