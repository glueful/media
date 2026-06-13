<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Unit;

use Glueful\Cache\CacheStore;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Extensions\Media\ImageProcessor;
use Glueful\Http\Exceptions\Domain\BusinessLogicException;
use Glueful\Services\ImageSecurityValidator;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\NullLogger;

/**
 * Safety guards for two image-processing entry points:
 *
 *  - fromUpload() must tolerate a PSR-7 upload whose getSize() returns null (size unknown) by
 *    SKIPPING the size cap rather than coercing null to a falsehood-asserting 0.
 *  - watermark() must NOT hand an unconfined path to the image manager's decode(): the 'center'
 *    position must survive odd dimension differences without a TypeError, and the watermark source
 *    must be confined to the configured watermark directory (no traversal, no URL/stream wrapper).
 */
final class WatermarkAndUploadSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];
    /** @var list<string> */
    private array $tempDirs = [];
    private ?string $watermarkDir = null;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required for watermark/upload safety tests.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }

    // ── (a) fromUpload() with an unknown size ───────────────────────────────────────────────────

    public function test_from_upload_with_null_size_skips_size_validation_and_succeeds(): void
    {
        $pngBytes = $this->pngBytes(10, 10);

        // A PSR-7 upload double whose getSize() returns null (size unknown). The previous `?? 0`
        // fix would have asserted a 0-byte size against the cap; the corrected behaviour skips the
        // cap entirely and the upload decodes normally.
        $upload = $this->uploadDouble($pngBytes, 'logo.png', 'image/png', size: null);

        $processor = ImageProcessor::fromUpload($upload, $this->context());

        self::assertSame(10, $processor->getWidth());
        self::assertSame(10, $processor->getHeight());
    }

    public function test_from_upload_with_known_oversize_still_rejected(): void
    {
        $pngBytes = $this->pngBytes(10, 10);

        // A concrete size over the 10M default cap must STILL be rejected — skipping only applies
        // when size is genuinely unknown (null).
        $upload = $this->uploadDouble($pngBytes, 'big.png', 'image/png', size: 11 * 1024 * 1024);

        $this->expectException(BusinessLogicException::class);
        ImageProcessor::fromUpload($upload, $this->context());
    }

    // ── (b) watermark() center position with an odd dimension difference ─────────────────────────

    public function test_watermark_center_with_odd_dimension_difference_does_not_throw(): void
    {
        // 100x100 base, 31x31 watermark -> (100 - 31) / 2 = 34.5, a float that would have thrown a
        // TypeError on insert()'s int $x/$y under strict_types before the (int) round() cast.
        $base = $this->makeProcessor();
        $base->decodeBytes($this->pngBytes(100, 100));

        $watermark = $this->writeWatermark('mark.png', 31, 31);

        $result = $base->watermark($watermark, 'center', 100);

        self::assertSame($base, $result);
        self::assertSame(100, $base->getWidth());
        self::assertSame(100, $base->getHeight());
    }

    // ── (c) watermark() path confinement ────────────────────────────────────────────────────────

    public function test_watermark_path_inside_allowed_base_succeeds(): void
    {
        $base = $this->makeProcessor();
        $base->decodeBytes($this->pngBytes(80, 60));

        $watermark = $this->writeWatermark('inside.png', 16, 16);

        $result = $base->watermark($watermark, 'bottom-right', 100);

        self::assertSame($base, $result);
    }

    public function test_watermark_path_outside_allowed_base_throws(): void
    {
        $base = $this->makeProcessor();
        $base->decodeBytes($this->pngBytes(80, 60));

        // A real PNG written OUTSIDE the configured watermark dir.
        $outside = tempnam(sys_get_temp_dir(), 'wm_outside_') . '.png';
        file_put_contents($outside, $this->pngBytes(16, 16));
        $this->tempFiles[] = $outside;

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessageMatches('/outside the allowed watermark directory/');
        $base->watermark($outside, 'center', 100);
    }

    public function test_watermark_php_stream_wrapper_throws(): void
    {
        $base = $this->makeProcessor();
        $base->decodeBytes($this->pngBytes(80, 60));

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessageMatches('/URL or stream wrapper/');
        $base->watermark('php://filter/read=convert.base64-encode/resource=/etc/passwd', 'center', 100);
    }

    public function test_watermark_http_url_throws(): void
    {
        $base = $this->makeProcessor();
        $base->decodeBytes($this->pngBytes(80, 60));

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessageMatches('/URL or stream wrapper/');
        $base->watermark('http://169.254.169.254/latest/meta-data/', 'center', 100);
    }

    public function test_watermark_traversal_sibling_dir_throws(): void
    {
        $base = $this->makeProcessor();
        $base->decodeBytes($this->pngBytes(80, 60));

        // A sibling dir sharing the base as a string prefix ("<base>-evil") must NOT satisfy the
        // separator-anchored prefix check.
        $sibling = $this->watermarkDir() . '-evil';
        @mkdir($sibling, 0o777, true);
        $this->tempDirs[] = $sibling;

        $evil = $sibling . DIRECTORY_SEPARATOR . 'x.png';
        file_put_contents($evil, $this->pngBytes(16, 16));
        $this->tempFiles[] = $evil;

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessageMatches('/outside the allowed watermark directory/');
        $base->watermark($evil, 'center', 100);
    }

    // ── Harness ─────────────────────────────────────────────────────────────────────────────────

    /**
     * A per-test watermark directory under the system temp dir, created on demand and torn down.
     */
    private function watermarkDir(): string
    {
        if ($this->watermarkDir === null) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'media_wm_' . bin2hex(random_bytes(6));
            @mkdir($dir, 0o777, true);
            $this->tempDirs[] = $dir;
            $this->watermarkDir = $dir;
        }

        return $this->watermarkDir;
    }

    private function writeWatermark(string $name, int $width, int $height): string
    {
        $path = $this->watermarkDir() . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $this->pngBytes($width, $height));
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Build a watermark-capable processor whose config pins paths.watermark_dir to this test's
     * sandbox directory. A small subclass exposes a decodeBytes() seam so the base image can be
     * loaded from raw bytes without going through the static factories.
     */
    private function makeProcessor(): ImageProcessor
    {
        /** @var CacheStore<mixed> $cache */
        $cache = new ArrayCacheDriver();

        return new class (
            new ImageManager(new Driver()),
            $cache,
            new ImageSecurityValidator(),
            new NullLogger(),
            ['paths' => ['watermark_dir' => $this->watermarkDir()]],
        ) extends ImageProcessor {
            public function decodeBytes(string $bytes): void
            {
                $ref = new \ReflectionProperty(ImageProcessor::class, 'image');
                $ref->setAccessible(true);
                $managerRef = new \ReflectionProperty(ImageProcessor::class, 'manager');
                $managerRef->setAccessible(true);
                $manager = $managerRef->getValue($this);
                $ref->setValue($this, $manager->decode($bytes));
            }
        };
    }

    private function context(): \Glueful\Bootstrap\ApplicationContext
    {
        $context = new \Glueful\Bootstrap\ApplicationContext(
            basePath: sys_get_temp_dir(),
            environment: 'testing'
        );

        $container = new \Glueful\Container\Container([
            ImageManager::class => static fn (): ImageManager => new ImageManager(new Driver()),
            'cache.store' => static fn (): ArrayCacheDriver => new ArrayCacheDriver(),
            ImageSecurityValidator::class => static fn (): ImageSecurityValidator => new ImageSecurityValidator(),
            \Psr\Log\LoggerInterface::class => static fn (): NullLogger => new NullLogger(),
        ]);
        $context->setContainer($container);

        return $context;
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 10, 20, 30);
        imagefilledrectangle($image, 0, 0, $width, $height, $color === false ? 0 : $color);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Minimal PSR-7 UploadedFileInterface double backed by an in-memory stream. Only the methods
     * fromUpload() touches are implemented with real behaviour; the rest throw to make accidental
     * reliance obvious.
     */
    private function uploadDouble(
        string $bytes,
        string $filename,
        string $mediaType,
        ?int $size
    ): UploadedFileInterface {
        $stream = new class ($bytes) implements StreamInterface {
            public function __construct(private string $contents)
            {
            }

            public function getContents(): string
            {
                return $this->contents;
            }

            public function __toString(): string
            {
                return $this->contents;
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return strlen($this->contents);
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return true;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
            }

            public function rewind(): void
            {
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                return $this->contents;
            }

            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };

        return new class ($stream, $filename, $mediaType, $size) implements UploadedFileInterface {
            public function __construct(
                private StreamInterface $stream,
                private string $filename,
                private string $mediaType,
                private ?int $size
            ) {
            }

            public function getStream(): StreamInterface
            {
                return $this->stream;
            }

            public function moveTo(string $targetPath): void
            {
                throw new \RuntimeException('not implemented');
            }

            public function getSize(): ?int
            {
                return $this->size;
            }

            public function getError(): int
            {
                return UPLOAD_ERR_OK;
            }

            public function getClientFilename(): ?string
            {
                return $this->filename;
            }

            public function getClientMediaType(): ?string
            {
                return $this->mediaType;
            }
        };
    }
}
