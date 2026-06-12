<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Unit;

use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Extensions\Media\ImageProcessor;
use Glueful\Services\ImageSecurityValidator;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Pins the cluster fixes in {@see ImageProcessor}:
 *  - cached() is read-or-populate (a warm store short-circuits the encoder), not write-only;
 *  - a hostile caller key is hashed into the cache-safe alphabet before it reaches the store;
 *  - getEncoder() honours the documented per-format config (progressive JPEG, lossless WebP).
 */
final class CacheAndEncoderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required for cache/encoder tests.');
        }
    }

    /**
     * Build a processor directly on a given cache store, bypassing the container factories. The
     * config carries a prefix + tags + format settings so the wiring under test is exercised.
     *
     * @param \Glueful\Cache\CacheStore<mixed> $cache
     * @param array<string, mixed> $config
     */
    private function processor(\Glueful\Cache\CacheStore $cache, array $config = []): ImageProcessor
    {
        $base = [
            'cache' => ['prefix' => 'image_', 'tags' => ['images', 'processed']],
            'security' => ['check_image_integrity' => true],
        ];

        $processor = new ImageProcessor(
            new ImageManager(new Driver()),
            $cache,
            new ImageSecurityValidator(),
            new NullLogger(),
            array_replace_recursive($base, $config),
        );

        // Seed a working image via the public make() factory's decode path by going through a
        // freshly created canvas is simplest: load the processor with an in-memory JPEG.
        $this->loadJpeg($processor, 40, 40);

        return $processor;
    }

    private function loadJpeg(ImageProcessor $processor, int $w, int $h): void
    {
        $gd = imagecreatetruecolor($w, $h);
        $c = imagecolorallocate($gd, 10, 20, 30);
        imagefilledrectangle($gd, 0, 0, $w, $h, $c === false ? 0 : $c);
        ob_start();
        imagejpeg($gd, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($gd);

        // Inject the decoded image into the private $image property.
        $ref = new \ReflectionProperty(ImageProcessor::class, 'image');
        $manager = new ImageManager(new Driver());
        $ref->setValue($processor, $manager->decode($bytes));
    }

    public function testWarmCacheReturnsCachedBytesWithoutReEncoding(): void
    {
        $store = new ArrayCacheDriver();

        // Produce a SENTINEL cached payload whose decoded dimensions (200x200) differ from the
        // live image the processor holds (40x40). If cached() truly reads-and-adopts, getWidth()
        // afterwards must report the SENTINEL's 200 — proving the bytes came from cache, not a
        // re-encode of the 40x40 working image.
        $sentinel = $this->jpegBytes(200, 200);
        $store->set('image_warmkey', [
            'image_data' => $sentinel,
            'mime_type' => 'image/jpeg',
            'width' => 200,
            'height' => 200,
            'operations' => [],
            'created_at' => time(),
        ], 3600);

        $processor = $this->processor($store);
        self::assertSame(40, $processor->getWidth(), 'precondition: live image is 40x40');

        $returned = $processor->cached('warmkey');

        self::assertSame($processor, $returned);
        self::assertSame(200, $processor->getWidth(), 'cached() must adopt the cached image bytes');
        self::assertSame(200, $processor->getHeight(), 'cached() must adopt the cached image bytes');
    }

    public function testColdCachePopulatesStoreAndTags(): void
    {
        $store = new ArrayCacheDriver();
        $processor = $this->processor($store);

        $processor->cached('coldkey');

        $stored = $store->get('image_coldkey');
        self::assertIsArray($stored);
        self::assertArrayHasKey('image_data', $stored);

        // Configured tags must have been associated for grouped invalidation.
        $store->invalidateTags(['images']);
        self::assertNull($store->get('image_coldkey'), 'tagged entry must be invalidated by its tag');
    }

    public function testHostileKeyIsSanitizedBeforeReachingStore(): void
    {
        $store = new ArrayCacheDriver();
        $processor = $this->processor($store);

        $processor->cached("evil key\n../x");

        // The raw hostile key must NEVER appear on the store; only a safe, hashed key may.
        foreach ($store->getAllKeys() as $key) {
            self::assertDoesNotMatchRegularExpression(
                '/[^A-Za-z0-9_.\-]/',
                $key,
                'cache key on the store must be confined to the safe alphabet'
            );
            self::assertStringStartsWith('image_', $key);
        }
        self::assertNull($store->get("image_evil key\n../x"), 'raw hostile key must not be a stored key');
        self::assertCount(1, $store->getAllKeys());
    }

    public function testSafeKeyIsPassedThroughUnchanged(): void
    {
        $store = new ArrayCacheDriver();
        $processor = $this->processor($store);

        $processor->cached('user.42_thumb-1');

        self::assertNotNull($store->get('image_user.42_thumb-1'));
    }

    public function testJpegEncoderHonoursProgressiveConfig(): void
    {
        $store = new ArrayCacheDriver();

        $off = $this->buildEncoder($this->processor($store, [
            'formats' => ['jpeg' => ['progressive' => false]],
        ]), 'jpeg');
        $on = $this->buildEncoder($this->processor($store, [
            'formats' => ['jpeg' => ['progressive' => true]],
        ]), 'jpeg');

        self::assertInstanceOf(JpegEncoder::class, $off);
        self::assertInstanceOf(JpegEncoder::class, $on);
        self::assertFalse($off->progressive);
        self::assertTrue($on->progressive, 'IMAGE_JPEG_PROGRESSIVE must reach the encoder');
    }

    public function testWebpEncoderHonoursLosslessConfig(): void
    {
        $store = new ArrayCacheDriver();

        $lossy = $this->buildEncoder($this->processor($store, [
            'formats' => ['webp' => ['lossless' => false]],
            'optimization' => ['webp_quality' => 70],
        ]), 'webp');
        $lossless = $this->buildEncoder($this->processor($store, [
            'formats' => ['webp' => ['lossless' => true]],
            'optimization' => ['webp_quality' => 70],
        ]), 'webp');

        self::assertInstanceOf(WebpEncoder::class, $lossy);
        self::assertInstanceOf(WebpEncoder::class, $lossless);
        // Lossless WebP in the v4 GD driver is expressed as quality === 100.
        self::assertSame(100, $lossless->quality, 'IMAGE_WEBP_LOSSLESS must force quality to 100');
        self::assertNotSame(100, $lossy->quality);
    }

    private function buildEncoder(ImageProcessor $processor, string $format): object
    {
        $m = new ReflectionMethod(ImageProcessor::class, 'getEncoder');
        /** @var object $encoder */
        $encoder = $m->invoke($processor, $format, 70);

        return $encoder;
    }

    private function jpegBytes(int $w, int $h): string
    {
        $gd = imagecreatetruecolor($w, $h);
        $c = imagecolorallocate($gd, 200, 100, 50);
        imagefilledrectangle($gd, 0, 0, $w, $h, $c === false ? 0 : $c);
        ob_start();
        imagejpeg($gd, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($gd);

        return $bytes;
    }
}
