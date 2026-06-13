<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Container\Container;
use Glueful\Extensions\Media\ImageProcessor;
use Glueful\Extensions\Media\MediaMetadataExtractor;
use Glueful\Extensions\Media\MediaProcessor;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Uploader\MediaMetadata;
use Glueful\Uploader\Storage\StorageInterface;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Behaviour tests for the core MediaProcessorInterface seam implementation.
 *
 * Collaborators are built around a GD-backed Intervention ImageManager (the
 * same driver ImageProvider selects by default in the framework) and an
 * in-memory array cache, wired into a minimal container so
 * ImageProcessor::make() can resolve itself exactly as it does in production.
 */
final class MediaProcessorTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required for MediaProcessor tests.');
        }

        $this->fixture = $this->makeFixture(120, 80);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture) && is_file($this->fixture)) {
            @unlink($this->fixture);
        }
    }

    public function testExtractMetadataReturnsImageDimensions(): void
    {
        $processor = $this->makeProcessor();

        $meta = $processor->extractMetadata($this->fixture, 'image/jpeg');

        self::assertInstanceOf(MediaMetadata::class, $meta);
        self::assertTrue($meta->isImage());
        self::assertSame(120, $meta->width);
        self::assertSame(80, $meta->height);
    }

    public function testRenderVariantResizesAndEncodes(): void
    {
        $processor = $this->makeProcessor();

        $variant = $processor->renderVariant($this->fixture, ['width' => 50]);

        self::assertArrayHasKey('data', $variant);
        self::assertArrayHasKey('mime', $variant);
        self::assertIsString($variant['data']);
        self::assertNotSame('', $variant['data']);
        self::assertStringStartsWith('image/', $variant['mime']);

        $info = getimagesizefromstring($variant['data']);
        self::assertNotFalse($info);
        // contain resize maintains aspect ratio; width clamps to ~50px.
        self::assertSame(50, $info[0]);
    }

    public function testRenderVariantHonoursFormatOption(): void
    {
        $processor = $this->makeProcessor();

        $variant = $processor->renderVariant($this->fixture, ['width' => 40, 'format' => 'png']);

        self::assertSame('image/png', $variant['mime']);
        $info = getimagesizefromstring($variant['data']);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    public function testSupportsThumbnail(): void
    {
        $processor = $this->makeProcessor();

        self::assertTrue($processor->supportsThumbnail('image/jpeg'));
        self::assertTrue($processor->supportsThumbnail('image/png'));
        self::assertFalse($processor->supportsThumbnail('application/pdf'));
        self::assertFalse($processor->supportsThumbnail('video/mp4'));
    }

    public function testGenerateThumbnailWritesThroughPassedStorage(): void
    {
        $processor = $this->makeProcessor();
        $storage = new SpyStorage('https://cdn.example.test/');

        $url = $processor->generateThumbnail(
            $storage,
            $this->fixture,
            'uploads/2026',
            'photo.jpg',
            ['width' => 32, 'height' => 32]
        );

        // The thumbnail must have landed on the PASSED storage, not some
        // processor-held storage. Prove it via the spy's recorded write + the
        // returned URL carrying the spy's prefix.
        self::assertCount(1, $storage->writes);
        $writtenPath = array_key_first($storage->writes);
        self::assertStringStartsWith('uploads/2026/thumbs/', $writtenPath);
        self::assertNotSame('', $storage->writes[$writtenPath]);

        self::assertIsString($url);
        self::assertSame('https://cdn.example.test/' . $writtenPath, $url);
    }

    private function makeProcessor(): MediaProcessor
    {
        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');

        $manager = new ImageManager(new Driver());
        /** @var CacheStore<mixed> $cache */
        $cache = new ArrayCacheDriver();
        $security = new ImageSecurityValidator();
        $logger = new NullLogger();

        // ImageProcessor::make() now builds a FRESH processor per call from the container's
        // collaborators (ImageManager/cache.store/validator/logger) rather than resolving a
        // pre-built shared ImageProcessor — so bind the collaborators, not the processor.
        $container = new Container([
            ImageManager::class => static fn (): ImageManager => $manager,
            'cache.store' => static fn (): CacheStore => $cache,
            ImageSecurityValidator::class => static fn (): ImageSecurityValidator => $security,
            \Psr\Log\LoggerInterface::class => static fn (): NullLogger => $logger,
        ]);
        $context->setContainer($container);

        return new MediaProcessor(new MediaMetadataExtractor(), $context);
    }

    private function makeFixture(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 30, 120, 200);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);

        $path = tempnam(sys_get_temp_dir(), 'media_fixture_') . '.jpg';
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return $path;
    }
}

/**
 * Records every write so the test can prove generateThumbnail() wrote through
 * THIS storage (the one passed to the seam), honouring the "never construct your
 * own storage" rule.
 */
final class SpyStorage implements StorageInterface
{
    /** @var array<string, string> path => content */
    public array $writes = [];

    public function __construct(private readonly string $urlPrefix)
    {
    }

    public function store(string $sourcePath, string $destinationPath): string
    {
        $this->writes[$destinationPath] = (string) @file_get_contents($sourcePath);
        return $this->getUrl($destinationPath);
    }

    public function storeContent(string $content, string $destinationPath): string
    {
        $this->writes[$destinationPath] = $content;
        return $this->getUrl($destinationPath);
    }

    public function getUrl(string $path): string
    {
        return $this->urlPrefix . $path;
    }

    public function exists(string $path): bool
    {
        return isset($this->writes[$path]);
    }

    public function delete(string $path): bool
    {
        unset($this->writes[$path]);
        return true;
    }

    public function getSignedUrl(string $path, int $expiry = 3600): string
    {
        return $this->getUrl($path) . '?expires=' . (time() + $expiry);
    }
}
