<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media;

use Glueful\Uploader\MediaMetadata;
use getID3;

/**
 * Extracts metadata from media files
 *
 * Uses getID3 library for pure PHP extraction of dimensions and duration
 * from images, video, and audio files. No external binaries required.
 *
 * Supported formats:
 * - Images: JPEG, PNG, GIF, WebP, BMP, TIFF
 * - Video: MP4, WebM, AVI, MOV, MKV, FLV
 * - Audio: MP3, WAV, OGG, FLAC, AAC, M4A
 *
 * Security posture: getID3 is a pure-PHP container parser with a history of
 * parser vulnerabilities on malformed MP4/AVI/etc. Untrusted uploads are
 * therefore (a) size-capped before analysis (abuse guard — see
 * MEDIA_METADATA_MAX_FILESIZE), (b) analysed with PHP warnings/notices and
 * Throwables contained, and (c) the strings/numbers that leave this extractor
 * are defensively sanitized because they originate in attacker-controlled
 * container metadata. Any failure degrades to a type-only MediaMetadata rather
 * than throwing out of the seam.
 */
final class MediaMetadataExtractor
{
    /**
     * Default abuse-guard cap on files handed to getID3. Media files are
     * legitimately large, so this is intentionally generous: it exists to stop
     * a single hostile/huge upload from exhausting a worker, not to constrain
     * normal use.
     */
    public const DEFAULT_MAX_FILESIZE = '500M';

    /** Maximum length, in characters, of any string copied out of getID3 output. */
    private const MAX_STRING_LENGTH = 100;

    private ?getID3 $getID3 = null;

    /** Resolved size cap in bytes; files larger than this are not analysed. */
    private readonly int $maxFilesizeBytes;

    /**
     * @param int|null $maxFilesizeBytes Hard cap (bytes) on files passed to
     *        getID3. When null, resolves from the MEDIA_METADATA_MAX_FILESIZE
     *        env value (default {@see self::DEFAULT_MAX_FILESIZE}). A value <= 0
     *        disables the cap.
     */
    public function __construct(?int $maxFilesizeBytes = null)
    {
        $this->maxFilesizeBytes = $maxFilesizeBytes ?? $this->resolveConfiguredCap();
    }

    /**
     * Extract metadata from a media file
     *
     * @param string $filepath Path to the media file
     * @param string $mimeType MIME type of the file
     * @return MediaMetadata Extracted metadata
     */
    public function extract(string $filepath, string $mimeType): MediaMetadata
    {
        $mediaType = $this->determineMediaType($mimeType);

        // For images, use getimagesize for reliability
        if ($mediaType === 'image') {
            return $this->extractImageMetadata($filepath);
        }

        // For video/audio, use getID3
        if ($mediaType === 'video' || $mediaType === 'audio') {
            return $this->extractWithGetID3($filepath, $mediaType);
        }

        return new MediaMetadata($mediaType);
    }

    /**
     * Determine media type category from MIME type
     */
    public function determineMediaType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'file',
        };
    }

    /**
     * Get detailed file info using getID3
     *
     * @param string $filepath Path to the media file
     * @return array<string, mixed> Raw getID3 analysis data
     */
    public function analyze(string $filepath): array
    {
        return $this->getAnalyzer()->analyze($filepath);
    }

    /**
     * Extract metadata from image file using getimagesize
     */
    private function extractImageMetadata(string $filepath): MediaMetadata
    {
        $imageInfo = @getimagesize($filepath);

        if ($imageInfo === false) {
            return new MediaMetadata('image');
        }

        return new MediaMetadata(
            type: 'image',
            width: $this->sanitizeDimension($imageInfo[0]),
            height: $this->sanitizeDimension($imageInfo[1]),
        );
    }

    /**
     * Extract metadata using getID3 library.
     *
     * Untrusted input path: the file is size-capped first, getID3 is run with
     * warnings/Throwables contained, and every value copied out is sanitized.
     * Any failure degrades to a type-only MediaMetadata.
     */
    private function extractWithGetID3(string $filepath, string $mediaType): MediaMetadata
    {
        if ($this->exceedsSizeCap($filepath)) {
            // Over-cap files are never handed to the parser: degrade to type-only.
            return new MediaMetadata($mediaType);
        }

        $info = $this->safeAnalyze($filepath);
        if ($info === null) {
            return new MediaMetadata($mediaType);
        }

        // Extract duration (defensively cast — origin is container metadata).
        $duration = null;
        if (isset($info['playtime_seconds']) && is_numeric($info['playtime_seconds'])) {
            $seconds = (float) $info['playtime_seconds'];
            if ($seconds > 0) {
                $duration = $this->sanitizeDimension((int) round($seconds));
            }
        }

        // Extract dimensions (for video)
        $width = null;
        $height = null;

        if ($mediaType === 'video') {
            // Try video stream info first
            if (isset($info['video']['resolution_x'])) {
                $width = $this->sanitizeDimension($info['video']['resolution_x']);
                $height = $this->sanitizeDimension($info['video']['resolution_y'] ?? 0);
            }

            // Fallback to streams array
            if ($width === null && isset($info['streams']) && is_iterable($info['streams'])) {
                foreach ($info['streams'] as $stream) {
                    if (is_array($stream) && isset($stream['resolution_x'])) {
                        $width = $this->sanitizeDimension($stream['resolution_x']);
                        $height = $this->sanitizeDimension($stream['resolution_y'] ?? 0);
                        break;
                    }
                }
            }
        }

        return new MediaMetadata(
            type: $mediaType,
            width: $width ?: null,
            height: $height ?: null,
            durationSeconds: $duration,
        );
    }

    /**
     * Run getID3 analysis with all parser warnings/notices and Throwables
     * contained. getID3 emits PHP warnings on malformed containers and can throw
     * on hostile input; neither must escape this seam.
     *
     * @return array<string, mixed>|null Analysis data, or null on any failure.
     */
    private function safeAnalyze(string $filepath): ?array
    {
        try {
            // @-suppress getID3's warnings/notices on malformed media so they
            // never surface to the caller (or trip PHPUnit's failOnWarning).
            $info = @$this->getAnalyzer()->analyze($filepath);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($info)) {
            return null;
        }

        // getID3 reports unreadable/unparseable files via an 'error' key rather
        // than always throwing; treat that as a failure too.
        if (isset($info['error'])) {
            return null;
        }

        return $info;
    }

    /**
     * Whether the file is missing or larger than the configured cap.
     */
    private function exceedsSizeCap(string $filepath): bool
    {
        if ($this->maxFilesizeBytes <= 0) {
            return false;
        }

        $size = @filesize($filepath);
        if ($size === false) {
            // Unreadable size — treat as unsafe and skip analysis.
            return true;
        }

        return $size > $this->maxFilesizeBytes;
    }

    /**
     * Coerce a getID3/getimagesize dimension value to a safe non-negative int,
     * or null when it is absent / non-numeric / non-positive.
     */
    public function sanitizeDimension(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * Strip control characters and cap the length of a string value originating
     * in (attacker-controlled) container metadata. Exposed for direct testing of
     * the sanitiser seam.
     */
    public function sanitizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        // Remove control characters (incl. NUL) but keep normal printable text.
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        if ($clean === null) {
            // Invalid UTF-8 — fall back to a byte-wise strip so we never return raw input.
            $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        }

        $clean = trim($clean);
        if ($clean === '') {
            return null;
        }

        if (mb_strlen($clean) > self::MAX_STRING_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_STRING_LENGTH);
        }

        return $clean;
    }

    /**
     * Resolve the configured size cap (bytes) from MEDIA_METADATA_MAX_FILESIZE.
     */
    private function resolveConfiguredCap(): int
    {
        $configured = self::DEFAULT_MAX_FILESIZE;
        if (\function_exists('env')) {
            $value = env('MEDIA_METADATA_MAX_FILESIZE', self::DEFAULT_MAX_FILESIZE);
            if (is_string($value) && $value !== '') {
                $configured = $value;
            }
        }

        return $this->parseSize($configured);
    }

    /**
     * Parse a human-readable size (e.g. '500M', '2G', '1024K') to bytes.
     * Matches the single-letter convention used across the media/image config.
     */
    private function parseSize(string $size): int
    {
        $size = trim($size);
        if ($size === '') {
            return 0;
        }

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
     * Get or create getID3 analyzer instance
     */
    private function getAnalyzer(): getID3
    {
        if ($this->getID3 === null) {
            $this->getID3 = new getID3();

            // Configure for performance
            $this->getID3->setOption(['encoding' => 'UTF-8']);
        }

        return $this->getID3;
    }
}
