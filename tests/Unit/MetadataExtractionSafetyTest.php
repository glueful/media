<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Unit;

use Glueful\Extensions\Media\MediaMetadataExtractor;
use Glueful\Uploader\MediaMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Safety tests for {@see MediaMetadataExtractor}.
 *
 * getID3 parses untrusted container formats (MP4/AVI/...) and has a history of
 * parser vulnerabilities; a huge or malformed file must never exhaust a worker
 * or leak warnings, and values copied out of attacker-controlled metadata must
 * be sanitized. Each case proves the extractor degrades to a type-only
 * MediaMetadata rather than throwing/analysing/leaking.
 *
 * No network, no real codecs: we drive the size cap via constructor injection
 * and feed garbage bytes for the malformed-container path.
 */
final class MetadataExtractionSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    public function testOverCapFileDegradesToTypeOnlyWithoutAnalysing(): void
    {
        // A 1 KiB "video" file with a 16-byte cap is over the limit and must be
        // skipped entirely (never handed to getID3).
        $path = $this->makeTempFile('over_cap_', str_repeat("\0", 1024));
        $extractor = new MediaMetadataExtractor(maxFilesizeBytes: 16);

        $meta = $extractor->extract($path, 'video/mp4');

        self::assertInstanceOf(MediaMetadata::class, $meta);
        self::assertTrue($meta->isVideo());
        self::assertNull($meta->width);
        self::assertNull($meta->height);
        self::assertNull($meta->durationSeconds);
    }

    public function testMissingFileDegradesGracefully(): void
    {
        $extractor = new MediaMetadataExtractor(maxFilesizeBytes: 0); // cap disabled

        $meta = $this->withoutLeakingWarnings(
            static fn (): MediaMetadata =>
                $extractor->extract('/no/such/file/at/all.mp4', 'video/mp4')
        );

        self::assertTrue($meta->isVideo());
        self::assertNull($meta->width);
        self::assertNull($meta->durationSeconds);
    }

    public function testMalformedVideoDegradesGracefullyWithoutLeakingWarnings(): void
    {
        // Garbage bytes with a .mp4 name: getID3 will emit warnings and/or set
        // an 'error' key. Nothing must escape and we must get type-only metadata.
        $garbage = random_bytes(2048);
        $path = $this->makeTempFile('malformed_', $garbage, '.mp4');
        $extractor = new MediaMetadataExtractor(maxFilesizeBytes: 0); // cap disabled

        $meta = $this->withoutLeakingWarnings(
            static fn (): MediaMetadata => $extractor->extract($path, 'video/mp4')
        );

        self::assertTrue($meta->isVideo());
        self::assertNull($meta->width);
        self::assertNull($meta->height);
        self::assertNull($meta->durationSeconds);
    }

    public function testMalformedAudioDegradesGracefully(): void
    {
        $path = $this->makeTempFile('malformed_audio_', random_bytes(2048), '.mp3');
        $extractor = new MediaMetadataExtractor(maxFilesizeBytes: 0);

        $meta = $this->withoutLeakingWarnings(
            static fn (): MediaMetadata => $extractor->extract($path, 'audio/mpeg')
        );

        self::assertTrue($meta->isAudio());
        self::assertNull($meta->durationSeconds);
    }

    public function testStringSanitizerStripsControlCharactersAndCapsLength(): void
    {
        $extractor = new MediaMetadataExtractor();

        // Control chars (NUL, ESC, DEL, newline) embedded in a codec/format name.
        $dirty = "h2\x0064\x1b[31m\x7f\nevil";
        $clean = $extractor->sanitizeString($dirty);

        self::assertNotNull($clean);
        self::assertSame('h264[31mevil', $clean);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $clean);

        // 10k chars caps to 100.
        $long = str_repeat('a', 10_000);
        $capped = $extractor->sanitizeString($long);
        self::assertNotNull($capped);
        self::assertSame(100, mb_strlen($capped));

        // Non-string and empty/whitespace-only collapse to null.
        self::assertNull($extractor->sanitizeString(123));
        self::assertNull($extractor->sanitizeString(null));
        self::assertNull($extractor->sanitizeString("\x00\x00"));
        self::assertNull($extractor->sanitizeString('   '));
    }

    public function testDimensionSanitizerRejectsNonPositiveAndNonNumeric(): void
    {
        $extractor = new MediaMetadataExtractor();

        self::assertSame(1920, $extractor->sanitizeDimension('1920'));
        self::assertSame(1080, $extractor->sanitizeDimension(1080));
        self::assertNull($extractor->sanitizeDimension(0));
        self::assertNull($extractor->sanitizeDimension(-5));
        self::assertNull($extractor->sanitizeDimension('not-a-number'));
        self::assertNull($extractor->sanitizeDimension(null));
    }

    private function makeTempFile(string $prefix, string $contents, string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix) . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Run a callback with a strict error handler installed so that ANY PHP
     * warning/notice escaping the extractor is turned into a failure. Proves the
     * extractor contains getID3's noisy output.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withoutLeakingWarnings(callable $fn): mixed
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \RuntimeException("Leaked PHP error ($errno): $errstr");
        });

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
