<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Glueful\Uploader\MediaMetadata;
use Glueful\Uploader\Storage\StorageInterface;

/**
 * Concrete implementation of the core upload-facing media seam.
 *
 * Composes the rich-media collaborators (ImageProcessor, ThumbnailGenerator,
 * MediaMetadataExtractor) that ship with this extension. Core consumes this
 * class only through {@see MediaProcessorInterface}; without the extension
 * installed the upload path falls back to dependency-free no-op behaviour.
 *
 * Storage is never held by this processor — it is passed per-call to
 * {@see generateThumbnail()} so thumbnails always land on the same disk the
 * upload used (honouring the seam's "never construct your own storage" rule).
 */
final class MediaProcessor implements MediaProcessorInterface
{
    public function __construct(
        private readonly MediaMetadataExtractor $metadataExtractor,
        private readonly ApplicationContext $context,
    ) {
    }

    public function extractMetadata(string $filepath, string $mimeType): MediaMetadata
    {
        return $this->metadataExtractor->extract($filepath, $mimeType);
    }

    public function generateThumbnail(
        StorageInterface $storage,
        string $sourcePath,
        string $storagePath,
        string $originalFilename,
        array $options = []
    ): ?string {
        // Build the generator over the CALLER'S storage so the thumbnail lands
        // on the same disk the upload used. Never construct our own storage.
        $generator = new ThumbnailGenerator($storage, $this->context);

        return $generator->generate($sourcePath, $storagePath, $originalFilename, $options);
    }

    public function supportsThumbnail(string $mimeType): bool
    {
        // Reuse the generator's format logic. A storage instance is required by
        // the constructor but is never touched by supports(), so a thin no-op
        // is acceptable here — generateThumbnail() always uses the real storage.
        return $this->newSupportProbe()->supports($mimeType);
    }

    /**
     * @param array<string, mixed> $options width,height,quality,format,fit
     * @return array{data: string, mime: string}
     */
    public function renderVariant(string $sourcePath, array $options): array
    {
        $processor = ImageProcessor::make($sourcePath, $this->context);

        $maxWidth = (int) $this->getConfig('uploads.image_processing.max_width', 2048);
        $maxHeight = (int) $this->getConfig('uploads.image_processing.max_height', 2048);

        $width = isset($options['width']) ? (int) $options['width'] : null;
        $height = isset($options['height']) ? (int) $options['height'] : null;

        if ($width !== null && $width > $maxWidth) {
            $width = $maxWidth;
        }
        if ($height !== null && $height > $maxHeight) {
            $height = $maxHeight;
        }

        $fit = isset($options['fit']) ? (string) $options['fit'] : 'contain';
        if ($fit === 'cover' && $width !== null && $height !== null) {
            $processor->fit($width, $height);
        } elseif ($fit === 'fill' && ($width !== null || $height !== null)) {
            $processor->resize($width, $height, false);
        } elseif ($width !== null || $height !== null) {
            $processor->resize($width, $height, true);
        }

        $quality = isset($options['quality']) ? (int) $options['quality'] : null;
        if ($quality !== null) {
            $processor->quality($quality);
        } else {
            $processor->quality((int) $this->getConfig('uploads.image_processing.default_quality', 85));
        }

        $format = isset($options['format']) ? (string) $options['format'] : null;
        if ($format !== null && $format !== '') {
            $processor->format($format);
        }

        return [
            'data' => $processor->getImageData($format),
            'mime' => $processor->getMimeType(),
        ];
    }

    /**
     * Create a ThumbnailGenerator used purely for its format-support logic.
     * It is never asked to write, so no caller storage is needed.
     */
    private function newSupportProbe(): ThumbnailGenerator
    {
        return new ThumbnailGenerator(new NullStorage(), $this->context);
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }

        return config($this->context, $key, $default);
    }
}
