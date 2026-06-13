<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Container\Container;
use Glueful\Extensions\Media\Contracts\ImageProcessorInterface;
use Glueful\Extensions\Media\ImageProcessor;
use Glueful\Services\ImageSecurityValidator;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regression guard for the "every factory returns the SAME mutated singleton" defect.
 *
 * The static factories used to resolve a SHARED container instance and merely swap its `image`,
 * so two images processed in one request (and, under persistent workers, across requests) aliased
 * one another's bytes. fresh() now constructs a clean instance per factory call. These tests pin
 * that two consecutive make() calls return DIFFERENT instances whose dimensions do not bleed, and
 * that the image() helper forwards the EXPLICIT context rather than the static default.
 */
final class ImageProcessorFreshnessTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required for ImageProcessor freshness tests.');
        }
    }

    protected function tearDown(): void
    {
        // Never leak a static default context to sibling tests.
        ImageProcessor::setContext(null);
    }

    /**
     * Build a context whose container resolves the collaborators fresh() needs. ImageManager,
     * cache.store, the validator and the logger are bound; the config helper falls back to []
     * (no global config), which fresh() tolerates.
     */
    private function context(): ApplicationContext
    {
        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');

        $container = new Container([
            ImageManager::class => static fn (): ImageManager => new ImageManager(new Driver()),
            'cache.store' => static fn (): ArrayCacheDriver => new ArrayCacheDriver(),
            ImageSecurityValidator::class => static fn (): ImageSecurityValidator => new ImageSecurityValidator(),
            \Psr\Log\LoggerInterface::class => static fn (): NullLogger => new NullLogger(),
        ]);
        $context->setContainer($container);

        return $context;
    }

    private function jpegFixture(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 30, 120, 200);
        imagefilledrectangle($image, 0, 0, $width, $height, $color === false ? 0 : $color);

        $path = tempnam(sys_get_temp_dir(), 'media_fresh_') . '.jpg';
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return $path;
    }

    public function testMakeReturnsFreshInstancePerCallWithoutStateBleed(): void
    {
        $context = $this->context();

        $small = $this->jpegFixture(120, 80);
        $large = $this->jpegFixture(300, 200);

        try {
            $first = ImageProcessor::make($small, $context);
            self::assertSame(120, $first->getWidth());
            self::assertSame(80, $first->getHeight());

            $second = ImageProcessor::make($large, $context);

            // Distinct instances...
            self::assertNotSame(
                $first,
                $second,
                'Two make() calls must return DIFFERENT instances (no shared singleton)'
            );
            self::assertSame(300, $second->getWidth());
            self::assertSame(200, $second->getHeight());

            // ...and the FIRST instance's image must NOT have been mutated by the second call.
            self::assertSame(120, $first->getWidth(), 'First image dimensions must survive a second make()');
            self::assertSame(80, $first->getHeight(), 'First image dimensions must survive a second make()');
        } finally {
            @unlink($small);
            @unlink($large);
        }
    }

    public function testImageHelperForwardsExplicitContext(): void
    {
        require_once __DIR__ . '/../../helpers.php';

        // Deliberately clear the static default so the helper can ONLY work if it forwards the
        // explicit argument through to make().
        ImageProcessor::setContext(null);

        $context = $this->context();
        $fixture = $this->jpegFixture(64, 48);

        try {
            $processor = image($context, $fixture);

            self::assertInstanceOf(ImageProcessorInterface::class, $processor);
            self::assertInstanceOf(ImageProcessor::class, $processor);
            self::assertSame(64, $processor->getWidth());
            self::assertSame(48, $processor->getHeight());
        } finally {
            @unlink($fixture);
        }
    }
}
