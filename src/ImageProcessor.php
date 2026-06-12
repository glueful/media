<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Media\Contracts\ImageProcessorInterface;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Direction;
use Intervention\Image\Encoders\AutoEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Glueful\Http\Exceptions\Domain\BusinessLogicException;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Cache\CacheStore;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Image Processor
 *
 * Modern image processing implementation using Intervention Image v4.
 * Provides fluent API for image transformations, caching, and output.
 */
class ImageProcessor implements ImageProcessorInterface
{
    private static ?ApplicationContext $defaultContext = null;

    private ImageManager $manager;
    private ImageInterface $image;
    /** @var CacheStore<mixed> */
    private CacheStore $cache;
    private ImageSecurityValidator $security;
    private LoggerInterface $logger;
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<int, array<int, mixed>> */
    private array $operations = [];
    private ?string $cacheKey = null;

    /**
     * @param CacheStore<mixed> $cache
     * @param array<string, mixed> $config
     */
    public function __construct(
        ImageManager $manager,
        CacheStore $cache,
        ImageSecurityValidator $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->manager = $manager;
        $this->cache = $cache;
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Static Factory Methods
     */

    public static function setContext(?ApplicationContext $context): void
    {
        self::$defaultContext = $context;
    }

    /**
     * Build a FRESH ImageProcessor from the container's collaborators.
     *
     * Every static factory routes through this so each call returns its own instance: the
     * mutable per-image state (image, operations, cacheKey, current_*) starts clean by
     * construction. Resolving the concrete id directly would hand back the same instance on a
     * shared binding and let two images processed in one request — or, under persistent workers,
     * across requests — stomp each other's bytes. The collaborators ARE resolved from the
     * container (so the CORE-owned ImageManager/cache.store/validator/logger are reused), and the
     * config array is assembled exactly as {@see MediaServiceProvider::defs()}'s factory closure
     * does, keeping behaviour identical to a direct container resolution.
     */
    private static function fresh(ApplicationContext $context): self
    {
        $getConfig = static fn(string $key): array => \function_exists('config')
            ? (array) config($context, $key, [])
            : [];

        return new self(
            app($context, ImageManager::class),
            app($context, 'cache.store'),
            // CORE-owned validator (StorageProvider) — resolved, never re-bound here.
            app($context, ImageSecurityValidator::class),
            app($context, LoggerInterface::class),
            [
                'optimization' => $getConfig('image.optimization'),
                'security' => $getConfig('image.security'),
                'cache' => $getConfig('image.cache'),
                'features' => $getConfig('image.features'),
                'defaults' => $getConfig('image.defaults'),
                'performance' => $getConfig('image.performance'),
                'monitoring' => $getConfig('image.monitoring'),
                // Needed so watermark() can confine the local watermark source to paths.watermark_dir.
                'paths' => $getConfig('image.paths'),
            ],
        );
    }

    public static function make(string $source, ?ApplicationContext $context = null): self
    {
        $instance = self::fresh(self::resolveContext($context));

        try {
            $instance->decodeSource($source);
            $instance->validateImage();

            $instance->logger->debug('Image loaded successfully', [
                'source' => $source,
                'width' => $instance->image->width(),
                'height' => $instance->image->height()
            ]);

            return $instance;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Failed to load image: ' . $e->getMessage()
            );
        }
    }

    /**
     * Decode a make() source into the working image. http(s) URLs are routed through the SAME
     * hardened fetch as fromUrl() so make() (and the image() helper) cannot be tricked into the
     * resolve-at-validate, fetch-at-decode DNS-rebinding TOCTOU that handing the URL string to
     * Intervention's decode() would allow. Non-URL sources (paths, data URIs, raw bytes) decode
     * unchanged.
     */
    protected function decodeSource(string $source): void
    {
        if (filter_var($source, FILTER_VALIDATE_URL) !== false && self::isHttpUrl($source)) {
            $this->image = $this->manager->decode($this->fetchRemoteImage($source, []));
            return;
        }

        $this->image = $this->manager->decode($source);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromUrl(
        string $url,
        array $options = [],
        ?ApplicationContext $context = null
    ): self {
        $instance = self::fresh(self::resolveContext($context));

        try {
            // Fetch with per-hop URL-policy + resolved-IP validation (SSRF defense), then decode.
            // Intervention v4's decode() has no stream-context parameter, so the bytes are fetched
            // here first.
            $contents = $instance->fetchRemoteImage($url, $options);
            $instance->image = $instance->manager->decode($contents);
            $instance->validateImage();

            $instance->logger->info('Remote image loaded', [
                'url' => $url,
                'width' => $instance->image->width(),
                'height' => $instance->image->height()
            ]);

            return $instance;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Failed to load remote image: ' . $e->getMessage()
            );
        }
    }

    /**
     * Fetch a remote image, following redirects MANUALLY so every hop is re-validated against the
     * URL policy and a resolved-IP range check. file_get_contents' own follow_location is disabled
     * because it would follow a redirect to an internal host (e.g. cloud metadata) unchecked.
     *
     * @param array<string, mixed> $options
     */
    protected function fetchRemoteImage(string $url, array $options): string
    {
        $maxRedirects = (int) ($this->config['security']['max_redirects'] ?? 3);
        // Clamp to a sane positive cap; a misconfigured non-positive value would otherwise disable
        // the length-capped read entirely.
        $cap = max(1, self::parseSizeToBytes((string) ($this->config['security']['max_file_size'] ?? '10M')));

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            // Policy gate (disable_external_urls + domain allow-list + core blocklist)...
            $this->security->validateUrl($url);
            // ...plus a self-sufficient resolved-IP check that rejects private/loopback/link-local
            // /reserved targets the core substring blocklist misses (169.254.169.254, ::1, ...).
            self::assertHostIsPublic($url);

            $context = stream_context_create([
                'http' => $this->buildStreamContextOptions($options),
            ]);

            // The HTTP stream wrapper populates $http_response_header in this scope; seed it so it
            // is defined even when the request fails before any response is received.
            $http_response_header = [];
            // Cap the read at the byte limit + 1: file_get_contents' 5th parameter ($length) is the
            // real guard here (PHP's http stream wrapper does NOT honor a 'max_length' context
            // option), so an oversized body is truncated rather than fully buffered. The +1 lets the
            // post-read length check below distinguish "exactly at cap" from "over cap".
            $body = @file_get_contents($url, false, $context, 0, $cap + 1);
            /** @var list<string> $responseHeaders */
            $responseHeaders = $http_response_header;
            $status = self::parseStatusCode($responseHeaders);

            if ($status >= 300 && $status < 400) {
                $location = self::parseLocationHeader($responseHeaders);
                if ($location === null) {
                    throw new \RuntimeException("Redirect without a Location header from: {$url}");
                }
                $url = self::resolveRedirectTarget($url, $location);
                continue;
            }

            if ($body === false || $status < 200 || $status >= 300) {
                throw new \RuntimeException("Unable to fetch remote image (HTTP {$status}): {$url}");
            }

            // Bail early when the server advertises a body larger than the cap, and enforce the cap
            // against what was actually read (the length-capped read is the authoritative guard).
            $advertised = self::parseContentLength($responseHeaders);
            if ($advertised !== null && $advertised > $cap) {
                throw new \RuntimeException(
                    "Remote image exceeds the maximum allowed size of {$cap} bytes: {$url}"
                );
            }
            if (strlen($body) > $cap) {
                throw new \RuntimeException(
                    "Remote image exceeds the maximum allowed size of {$cap} bytes: {$url}"
                );
            }

            return $body;
        }

        throw new \RuntimeException("Too many redirects while fetching remote image: {$url}");
    }

    /**
     * Build the `http` stream-context options for a remote fetch. Caller-supplied $options are
     * merged FIRST, then the security-critical keys are forced unconditionally — they are NOT
     * caller-configurable, so a malicious `$options` array cannot re-enable auto-follow (which
     * would bypass the per-hop URL/IP validation) or swallow 3xx responses.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildStreamContextOptions(array $options): array
    {
        return array_merge(
            [
                'timeout' => (int) ($this->config['security']['timeout'] ?? 10),
                'user_agent' => $this->config['security']['user_agent'] ?? 'Glueful-ImageProcessor/1.0',
            ],
            $options,
            [
                // Forced last so caller $options can never override them.
                'method' => 'GET',
                'follow_location' => 0,  // never auto-follow; redirects are handled manually per hop
                'max_redirects' => 1,
                'ignore_errors' => true, // capture 3xx status/headers instead of failing the read
            ],
        );
    }

    /**
     * Parse a size shorthand ('10M', '512K', '1G') or raw byte count to bytes. Mirrors the core
     * ImageSecurityValidator's parser so the remote download cap and upload cap agree.
     */
    private static function parseSizeToBytes(string $size): int
    {
        $size = trim($size);
        $unit = strtoupper(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $size,
        };
    }

    /**
     * Whether a string is an http(s) URL (the only schemes routed through the hardened fetch).
     */
    private static function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return $scheme === 'http' || $scheme === 'https';
    }

    /**
     * Reject a URL whose host resolves to a non-public address (SSRF defense).
     */
    private static function assertHostIsPublic(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('Remote image URL has no host');
        }
        $host = trim($host, '[]'); // strip IPv6 literal brackets

        foreach (self::resolveHostIps($host) as $ip) {
            if (self::isDisallowedIp($ip)) {
                throw new \RuntimeException('Remote image host resolves to a disallowed address');
            }
        }
    }

    /**
     * Resolve a host to all of its IP addresses (or the literal IP itself). Fails closed when a
     * host cannot be resolved.
     *
     * @return list<string>
     */
    private static function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $v4 = gethostbynamel($host);
        $ips = is_array($v4) ? $v4 : [];

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            throw new \RuntimeException("Unable to resolve remote image host: {$host}");
        }

        return array_values($ips);
    }

    /**
     * Whether an IP is in a private, loopback, link-local, or otherwise reserved range and must
     * therefore NOT be fetched. Replaces substring blocklisting.
     */
    private static function isDisallowedIp(string $ip): bool
    {
        // Normalize an IPv4-mapped IPv6 address (::ffff:127.0.0.1) to its IPv4 form.
        if (preg_match('#^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$#i', $ip, $m) === 1) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true; // not a valid IP -> reject
        }

        // Belt-and-suspenders for IPv6 loopback/link-local/ULA ranges the filter flags do not
        // reliably cover across PHP versions.
        $lower = strtolower($ip);
        if (
            $lower === '::1'
            || str_starts_with($lower, 'fe80:')
            || str_starts_with($lower, 'fc')
            || str_starts_with($lower, 'fd')
        ) {
            return true;
        }

        // Reject private + reserved ranges (RFC1918, loopback 127/8, link-local 169.254/16,
        // 0.0.0.0/8, and other reserved blocks).
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Parse the final HTTP status code from a list of response header lines.
     *
     * @param list<string> $headers
     */
    private static function parseStatusCode(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    /**
     * Parse the last Content-Length header (in bytes) from a list of response header lines, or null
     * when absent/unparseable.
     *
     * @param list<string> $headers
     */
    private static function parseContentLength(array $headers): ?int
    {
        $length = null;
        foreach ($headers as $header) {
            if (preg_match('#^Content-Length:\s*(\d+)\s*$#i', $header, $m) === 1) {
                $length = (int) $m[1];
            }
        }
        return $length;
    }

    /**
     * Parse the last Location header from a list of response header lines.
     *
     * @param list<string> $headers
     */
    private static function parseLocationHeader(array $headers): ?string
    {
        $location = null;
        foreach ($headers as $header) {
            if (preg_match('#^Location:\s*(.+)$#i', $header, $m) === 1) {
                $candidate = trim($m[1]);
                if ($candidate !== '') {
                    $location = $candidate;
                }
            }
        }
        return $location;
    }

    /**
     * Resolve a redirect Location (absolute, root-relative, or path-relative) against the URL it
     * was returned from, so the next hop can be re-validated.
     */
    private static function resolveRedirectTarget(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }

        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

        return $origin . $dir . $location;
    }

    public static function fromUpload(UploadedFileInterface $file, ?ApplicationContext $context = null): self
    {
        $instance = self::fresh(self::resolveContext($context));

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'File upload error: ' . $file->getError()
            );
        }

        // Validate file size. PSR-7 getSize(): ?int may return null (size unknown — e.g. a
        // non-seekable stream). When it does, SKIP the size cap rather than coerce null to 0: a
        // 0-byte "size" would silently pass the cap and assert a falsehood about the upload. The
        // decode-time dimension/integrity checks in validateImage() still bound the real payload.
        $size = $file->getSize();
        if ($size !== null) {
            $instance->security->validateFileSize($size);
        }

        // Validate format
        $filename = $file->getClientFilename() ?? 'upload';
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $instance->security->validateFormat($extension, $file->getClientMediaType());

        try {
            $instance->image = $instance->manager->decode($file->getStream()->getContents());
            $instance->validateImage();

            return $instance;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Failed to process uploaded file: ' . $e->getMessage()
            );
        }
    }

    public static function create(
        int $width,
        int $height,
        string $background = 'ffffff',
        ?ApplicationContext $context = null
    ): self {
        $instance = self::fresh(self::resolveContext($context));

        // Validate dimensions
        $instance->security->validateDimensions($width, $height);

        try {
            $instance->image = $instance->manager->createImage($width, $height)->fill($background);

            $instance->logger->debug('Blank canvas created', [
                'width' => $width,
                'height' => $height,
                'background' => $background
            ]);

            return $instance;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Failed to create canvas: ' . $e->getMessage()
            );
        }
    }

    /**
     * Transformation Operations
     */

    public function resize(?int $width = null, ?int $height = null, bool $maintainAspect = true): self
    {
        if ($width !== null && $width > 0) {
            $this->security->validateDimensions($width, $height ?? $width);
        }
        if ($height !== null && $height > 0) {
            $this->security->validateDimensions($width ?? $height, $height);
        }

        $this->operations[] = ['resize', compact('width', 'height', 'maintainAspect')];

        if ($maintainAspect) {
            $this->image = $this->image->scale($width, $height);
        } else {
            $this->image = $this->image->resize($width, $height);
        }

        return $this;
    }

    private static function resolveContext(?ApplicationContext $context): ApplicationContext
    {
        $resolved = $context ?? self::$defaultContext;
        if ($resolved === null) {
            throw new \RuntimeException('ApplicationContext is required for ImageProcessor factories.');
        }

        return $resolved;
    }

    public function crop(int $width, int $height, ?int $x = null, ?int $y = null): self
    {
        $this->security->validateDimensions($width, $height);

        $this->operations[] = ['crop', compact('width', 'height', 'x', 'y')];

        if ($x !== null && $y !== null) {
            $this->image = $this->image->crop($width, $height, $x, $y);
        } else {
            $this->image = $this->image->crop($width, $height);
        }

        return $this;
    }

    public function fit(int $width, int $height, string $position = 'center'): self
    {
        $this->security->validateDimensions($width, $height);

        $this->operations[] = ['fit', compact('width', 'height', 'position')];

        // Scale to fit and crop to exact dimensions
        $this->image = $this->image->cover($width, $height, $position);

        return $this;
    }

    public function quality(int $quality): self
    {
        $this->security->validateQuality($quality);

        $this->operations[] = ['quality', compact('quality')];

        // Quality is applied during save/encode
        $this->config['current_quality'] = $quality;

        return $this;
    }

    public function format(string $format): self
    {
        $this->security->validateFormat($format);

        $this->operations[] = ['format', compact('format')];
        $this->config['current_format'] = $format;

        return $this;
    }

    public function optimize(): self
    {
        $this->operations[] = ['optimize', []];

        // Enable optimization flags
        $this->config['optimize'] = true;

        return $this;
    }

    public function rotate(float $degrees, string $background = 'ffffff'): self
    {
        $this->operations[] = ['rotate', compact('degrees', 'background')];

        $this->image = $this->image->rotate($degrees, $background);

        return $this;
    }

    public function flipHorizontal(): self
    {
        $this->operations[] = ['flipHorizontal', []];

        $this->image = $this->image->flip(Direction::HORIZONTAL);

        return $this;
    }

    public function flipVertical(): self
    {
        $this->operations[] = ['flipVertical', []];

        $this->image = $this->image->flip(Direction::VERTICAL);

        return $this;
    }

    public function watermark(string $watermarkPath, string $position = 'bottom-right', int $opacity = 50): self
    {
        if (($this->config['features']['watermarking'] ?? true) === false) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Watermarking is disabled'
            );
        }

        // Confine the watermark source to a trusted base BEFORE the manager reads it: an
        // unvalidated path (or stream-wrapper string) handed to decode() is an arbitrary-file-read
        // primitive (e.g. /etc/passwd, php://filter, http://internal).
        $resolvedWatermark = $this->resolveWatermarkPath($watermarkPath);

        $this->operations[] = ['watermark', compact('watermarkPath', 'position', 'opacity')];

        try {
            $watermark = $this->manager->decode($resolvedWatermark);

            // Apply opacity
            if ($opacity < 100) {
                // Apply opacity through color manipulation
                $watermark = $watermark->reduceColors(256);
            }

            // Position calculation
            $positions = $this->calculateWatermarkPosition($position, $watermark);

            $this->image = $this->image->insert($watermark, $positions['x'], $positions['y'], 'top-left');

            return $this;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Watermark failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Output Methods
     */

    public function save(string $path): bool
    {
        try {
            $quality = $this->config['current_quality'] ?? $this->getDefaultQuality();

            $this->image->save($path, quality: $quality);

            $this->logger->info('Image saved successfully', [
                'path' => $path,
                'operations' => count($this->operations),
                'final_size' => filesize($path)
            ]);

            return true;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Save failed: ' . $e->getMessage()
            );
        }
    }

    public function cached(?string $key = null, int $ttl = 3600): self
    {
        $this->cacheKey = $key ?? $this->generateCacheKey();

        try {
            $cacheData = [
                'image_data' => $this->getImageData(),
                'mime_type' => $this->getMimeType(),
                'width' => $this->getWidth(),
                'height' => $this->getHeight(),
                'operations' => $this->operations,
                'created_at' => time()
            ];

            $fullCacheKey = ($this->config['cache']['prefix'] ?? 'image_') . $this->cacheKey;

            $this->cache->set($fullCacheKey, $cacheData, $ttl);

            $this->logger->debug('Image cached', [
                'cache_key' => $this->cacheKey,
                'ttl' => $ttl,
                'size' => strlen($cacheData['image_data'])
            ]);

            return $this;
        } catch (\Exception $e) {
            $this->logger->warning('Cache save failed', [
                'error' => $e->getMessage(),
                'cache_key' => $this->cacheKey
            ]);

            // Don't throw - caching is optional
            return $this;
        }
    }

    public function toBase64(?string $format = null): string
    {
        $imageData = $this->getImageData($format);
        $mimeType = ($format !== null && $format !== '') ? "image/{$format}" : $this->getMimeType();

        return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
    }

    public function getImageData(?string $format = null): string
    {
        $format = $format ?? $this->config['current_format'] ?? null;
        $quality = $this->config['current_quality'] ?? $this->getDefaultQuality();

        try {
            if ($format !== null && $format !== '') {
                $encoder = $this->getEncoder($format, $quality);
                return (string) $this->image->encode($encoder);
            } else {
                return (string) $this->image->encode(new AutoEncoder(quality: $quality));
            }
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Encode failed: ' . $e->getMessage()
            );
        }
    }


    /**
     * @param array<string, string> $headers
     */
    public function stream(array $headers = []): void
    {
        $imageData = $this->getImageData();
        $mimeType = $this->getMimeType();

        // Set headers
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . strlen($imageData));
        header('Cache-Control: public, max-age=3600');

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $imageData;
    }

    /**
     * Information Methods
     */

    public function getWidth(): int
    {
        return $this->image->width();
    }

    public function getHeight(): int
    {
        return $this->image->height();
    }

    public function getMimeType(): string
    {
        // Get MIME type from current format or original
        $format = $this->config['current_format'] ?? null;

        if ($format !== null && $format !== '') {
            return match ($format) {
                'jpeg', 'jpg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'image/jpeg'
            };
        }

        return $this->image->origin()->mediaType();
    }

    public function getFileSize(): int
    {
        return strlen($this->getImageData());
    }

    public function isValid(): bool
    {
        try {
            return $this->image->width() > 0 && $this->image->height() > 0;
        } catch (\Exception) {
            return false;
        }
    }

    public function getAspectRatio(): float
    {
        $height = $this->getHeight();
        return $height > 0 ? $this->getWidth() / $height : 1.0;
    }

    public function hasTransparency(): bool
    {
        // Check if image has alpha channel by sampling colors
        try {
            // For a more accurate transparency check, we could sample multiple pixels
            // but for now, assume PNG/GIF formats typically have transparency
            $mediaType = $this->image->origin()->mediaType();
            $format = strtolower($mediaType);
            return strpos($format, 'png') !== false || strpos($format, 'gif') !== false;
        } catch (\Exception) {
            // Fallback: assume no transparency if we can't determine
            return false;
        }
    }

    /**
     * Advanced Operations
     */

    public function modify(callable $callback): self
    {
        $this->operations[] = ['modify', ['callback' => 'custom']];

        try {
            $callback($this->image);
            return $this;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Custom modification failed: ' . $e->getMessage()
            );
        }
    }

    public function clone(): self
    {
        $clone = new self(
            $this->manager,
            $this->cache,
            $this->security,
            $this->logger,
            $this->config
        );

        $clone->image = clone $this->image;
        $clone->operations = $this->operations;

        return $clone;
    }

    public function reset(): self
    {
        $this->operations = [];
        $this->cacheKey = null;
        unset($this->config['current_quality'], $this->config['current_format']);

        return $this;
    }

    /**
     * Private Helper Methods
     */

    private function validateImage(): void
    {
        if (!isset($this->image)) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Invalid image data'
            );
        }

        // Validate dimensions
        $this->security->validateDimensions($this->image->width(), $this->image->height());

        // Additional integrity checks
        if (($this->config['security']['check_image_integrity'] ?? true) === true) {
            if ($this->image->width() <= 0 || $this->image->height() <= 0) {
                throw BusinessLogicException::operationNotAllowed(
                    'image_processing',
                    'Invalid image dimensions'
                );
            }
        }
    }

    /**
     * Resolve and confine a watermark source to a trusted local directory.
     *
     * The raw $watermarkPath reaches the image manager's decode(), which reads any path or PHP
     * stream-wrapper string it is given — so an unconfined value is an arbitrary-file-read /SSRF
     * primitive (`/etc/passwd`, `php://filter/...`, `http://169.254.169.254/...`). This rejects
     * URLs and stream wrappers outright, then requires the canonical realpath() to exist AND sit
     * inside the configured watermark base (paths.watermark_dir, defaulting to the system temp dir
     * when unset). The returned path is the validated realpath.
     *
     * @throws BusinessLogicException If the path is a URL/stream wrapper, missing, or escapes the base.
     */
    private function resolveWatermarkPath(string $watermarkPath): string
    {
        // Reject any scheme-prefixed value (http://, https://, php://, file://, data://, ...). A bare
        // local path has no "://" wrapper separator.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $watermarkPath) === 1) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Watermark path must be a local file, not a URL or stream wrapper'
            );
        }

        $baseConfigured = (string) ($this->config['paths']['watermark_dir'] ?? '');
        $base = $baseConfigured !== '' ? $baseConfigured : sys_get_temp_dir();

        $realBase = realpath($base);
        $realPath = realpath($watermarkPath);

        if ($realBase === false || $realPath === false) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Watermark file does not exist'
            );
        }

        // Prefix-match with a trailing separator so "/base/watermarks" cannot be satisfied by a
        // sibling like "/base/watermarks-evil/x.png".
        $realBaseWithSep = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath . DIRECTORY_SEPARATOR, $realBaseWithSep)) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Watermark path is outside the allowed watermark directory'
            );
        }

        return $realPath;
    }

    /**
     * @return array{x: int, y: int}
     */
    private function calculateWatermarkPosition(string $position, ImageInterface $watermark): array
    {
        $imageWidth = $this->getWidth();
        $imageHeight = $this->getHeight();
        $watermarkWidth = $watermark->width();
        $watermarkHeight = $watermark->height();

        return match ($position) {
            'top-left' => ['x' => 10, 'y' => 10],
            'top-right' => ['x' => $imageWidth - $watermarkWidth - 10, 'y' => 10],
            'bottom-left' => ['x' => 10, 'y' => $imageHeight - $watermarkHeight - 10],
            'bottom-right' => ['x' => $imageWidth - $watermarkWidth - 10, 'y' => $imageHeight - $watermarkHeight - 10],
            // PHP's `/` always yields a float, so an odd width/height difference would hand a float
            // to insert()'s `int $x`/`int $y` and throw a TypeError under strict_types. Round to the
            // nearest whole pixel and cast back to int.
            'center' => [
                'x' => (int) round(($imageWidth - $watermarkWidth) / 2),
                'y' => (int) round(($imageHeight - $watermarkHeight) / 2)
            ],
            default => ['x' => $imageWidth - $watermarkWidth - 10, 'y' => $imageHeight - $watermarkHeight - 10]
        };
    }

    private function generateCacheKey(): string
    {
        $data = [
            'operations' => $this->operations,
            'config' => [
                'quality' => $this->config['current_quality'] ?? null,
                'format' => $this->config['current_format'] ?? null,
            ],
            'dimensions' => [$this->getWidth(), $this->getHeight()]
        ];

        return md5(serialize($data));
    }

    private function getDefaultQuality(): int
    {
        $format = $this->config['current_format'] ?? 'jpeg';

        return match ($format) {
            'jpeg', 'jpg' => $this->config['optimization']['jpeg_quality'] ?? 85,
            'webp' => $this->config['optimization']['webp_quality'] ?? 80,
            'png' => 100, // PNG is lossless
            'gif' => $this->config['optimization']['gif_quality'] ?? 85,
            default => 85
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'optimization' => [
                'jpeg_quality' => 85,
                'webp_quality' => 80,
                'gif_quality' => 85,
            ],
            'security' => [
                'timeout' => 10,
                'user_agent' => 'Glueful-ImageProcessor/1.0',
                'check_image_integrity' => true,
            ],
            'cache' => [
                'prefix' => 'image_',
            ],
            'features' => [
                'watermarking' => true,
                'format_conversion' => true,
            ]
        ];
    }

    /**
     * Get appropriate encoder for format and quality
     *
     * @param string $format Image format
     * @param int $quality Quality setting
     * @return \Intervention\Image\Interfaces\EncoderInterface
     */
    private function getEncoder(string $format, int $quality): \Intervention\Image\Interfaces\EncoderInterface
    {
        return match (strtolower($format)) {
            'jpg', 'jpeg' => new JpegEncoder(quality: $quality),
            'png' => new PngEncoder(),
            'gif' => new GifEncoder(),
            'webp' => new WebpEncoder(quality: $quality),
            default => new AutoEncoder(quality: $quality)
        };
    }
}
