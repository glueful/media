<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Unit;

use Glueful\Extensions\Media\Contracts\ImageProcessorInterface;
use Glueful\Extensions\Media\ImageProcessor;
use Glueful\Extensions\Media\MediaMetadataExtractor;
use Glueful\Extensions\Media\ThumbnailGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test: the copied rich-media classes autoload under the extension
 * namespace and ImageProcessor implements the extension's interface.
 */
final class AutoloadTest extends TestCase
{
    public function testInterfaceAutoloads(): void
    {
        self::assertTrue(interface_exists(ImageProcessorInterface::class));
    }

    public function testImageProcessorAutoloads(): void
    {
        self::assertTrue(class_exists(ImageProcessor::class));
    }

    public function testThumbnailGeneratorAutoloads(): void
    {
        self::assertTrue(class_exists(ThumbnailGenerator::class));
    }

    public function testMediaMetadataExtractorAutoloads(): void
    {
        self::assertTrue(class_exists(MediaMetadataExtractor::class));
    }

    public function testImageProcessorImplementsExtensionInterface(): void
    {
        self::assertTrue(
            is_subclass_of(ImageProcessor::class, ImageProcessorInterface::class)
        );
    }
}
