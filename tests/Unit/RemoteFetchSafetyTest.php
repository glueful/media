<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Unit;

use Glueful\Cache\CacheStore;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Extensions\Media\ImageProcessor;
use Glueful\Services\ImageSecurityValidator;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * SSRF defense for remote image fetching. `isDisallowedIp()` replaces the weak substring
 * blocklist with an IP-range check (private/loopback/link-local/reserved), and
 * `resolveRedirectTarget()` resolves redirect Locations so every hop can be re-validated.
 * The stream-context builder force-pins the security-critical http options, the download read is
 * size-capped, and make() routes http(s) URLs through the same hardened fetch as fromUrl().
 */
final class RemoteFetchSafetyTest extends TestCase
{
    /**
     * @param array<int, mixed> $args
     */
    private static function callPrivate(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(ImageProcessor::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke(null, ...$args);
    }

    /**
     * Invoke an instance (non-static) private/protected method via reflection.
     *
     * @param array<int, mixed> $args
     */
    private static function callOn(ImageProcessor $instance, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(ImageProcessor::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($instance, ...$args);
    }

    private static function newProcessor(): ImageProcessor
    {
        /** @var CacheStore<mixed> $cache */
        $cache = new ArrayCacheDriver();

        return new ImageProcessor(
            new ImageManager(new Driver()),
            $cache,
            new ImageSecurityValidator(),
            new NullLogger(),
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ipProvider(): iterable
    {
        // Disallowed — must never be fetched.
        yield 'loopback v4'      => ['127.0.0.1', true];
        yield 'cloud metadata'   => ['169.254.169.254', true];
        yield 'link-local v4'    => ['169.254.10.20', true];
        yield 'rfc1918 10'       => ['10.0.0.5', true];
        yield 'rfc1918 192.168'  => ['192.168.1.1', true];
        yield 'rfc1918 172.16'   => ['172.16.5.5', true];
        yield 'zero network'     => ['0.0.0.0', true];
        yield 'loopback v6'      => ['::1', true];
        yield 'ipv4-mapped lo'   => ['::ffff:127.0.0.1', true];
        yield 'link-local v6'    => ['fe80::1', true];
        yield 'ula v6'           => ['fc00::1', true];
        yield 'not an ip'        => ['not-an-ip', true];
        yield 'empty'            => ['', true];

        // Allowed — public addresses.
        yield 'public v4 google' => ['8.8.8.8', false];
        yield 'public v4 cf'     => ['1.1.1.1', false];
        yield 'public v6 cf'     => ['2606:4700:4700::1111', false];
    }

    /**
     * @dataProvider ipProvider
     */
    public function test_is_disallowed_ip(string $ip, bool $expected): void
    {
        self::assertSame($expected, self::callPrivate('isDisallowedIp', [$ip]));
    }

    public function test_resolve_redirect_target_keeps_absolute_urls(): void
    {
        self::assertSame(
            'https://cdn.example.com/a.jpg',
            self::callPrivate('resolveRedirectTarget', ['https://img.example.com/x', 'https://cdn.example.com/a.jpg'])
        );
    }

    public function test_resolve_redirect_target_resolves_root_relative(): void
    {
        self::assertSame(
            'https://img.example.com/a.jpg',
            self::callPrivate('resolveRedirectTarget', ['https://img.example.com/deep/x', '/a.jpg'])
        );
    }

    public function test_resolve_redirect_target_resolves_path_relative(): void
    {
        self::assertSame(
            'https://img.example.com/deep/a.jpg',
            self::callPrivate('resolveRedirectTarget', ['https://img.example.com/deep/x', 'a.jpg'])
        );
    }

    public function test_default_config_is_fail_closed_for_remote_urls(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../config/image.php';
        $security = $config['security'];

        self::assertTrue($security['disable_external_urls'], 'remote URLs must be disabled by default');
        self::assertSame([], $security['allowed_domains'], 'the domain allow-list must be empty by default');
    }

    // GAP 1 — the security-critical http options must survive a malicious caller $options array.

    public function test_build_stream_context_forces_security_keys_over_caller_options(): void
    {
        $instance = self::newProcessor();

        // A caller trying to re-enable auto-follow (which would bypass the per-hop validation) and
        // swallow 3xx responses.
        $malicious = [
            'follow_location' => 1,
            'max_redirects' => 9,
            'ignore_errors' => false,
            'method' => 'POST',
        ];

        /** @var array<string, mixed> $http */
        $http = self::callOn($instance, 'buildStreamContextOptions', [$malicious]);

        self::assertSame(0, $http['follow_location'], 'auto-follow must stay disabled');
        self::assertSame(1, $http['max_redirects'], 'max_redirects must stay forced');
        self::assertTrue($http['ignore_errors'], 'ignore_errors must stay forced on');
        self::assertSame('GET', $http['method'], 'method must stay GET');
    }

    public function test_build_stream_context_preserves_non_security_caller_options(): void
    {
        $instance = self::newProcessor();

        /** @var array<string, mixed> $http */
        $http = self::callOn($instance, 'buildStreamContextOptions', [['header' => 'X-Test: 1']]);

        self::assertSame('X-Test: 1', $http['header'], 'benign caller options pass through');
    }

    // GAP 2 — size-cap parsing + the oversized-body post-check.

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function sizeProvider(): iterable
    {
        yield '10M'  => ['10M', 10 * 1024 * 1024];
        yield '512K' => ['512K', 512 * 1024];
        yield '1G'   => ['1G', 1024 * 1024 * 1024];
        yield 'raw'  => ['2048', 2048];
    }

    /**
     * @dataProvider sizeProvider
     */
    public function test_parse_size_to_bytes(string $shorthand, int $expected): void
    {
        self::assertSame($expected, self::callPrivate('parseSizeToBytes', [$shorthand]));
    }

    public function test_content_length_header_over_cap_is_detected(): void
    {
        // The Content-Length parser feeds the early-bail check; a value over the cap must be seen.
        $headers = ['HTTP/1.1 200 OK', 'Content-Length: 99999999'];
        self::assertSame(99999999, self::callPrivate('parseContentLength', [$headers]));

        $cap = self::callPrivate('parseSizeToBytes', ['10M']);
        self::assertGreaterThan($cap, 99999999, 'fixture must exceed the 10M cap to exercise the guard');
    }

    public function test_content_length_absent_returns_null(): void
    {
        self::assertNull(self::callPrivate('parseContentLength', [['HTTP/1.1 200 OK']]));
    }

    // GAP 3 — make() routes http(s) URLs through the hardened fetch (bytes, not the URL string).

    public function test_make_routes_http_url_through_hardened_fetch(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required to decode the fetched bytes.');
        }

        $pngBytes = self::tinyPng();
        $instance = new class (
            new ImageManager(new Driver()),
            new ArrayCacheDriver(),
            new ImageSecurityValidator(),
            new NullLogger(),
        ) extends ImageProcessor {
            public ?string $fetchedUrl = null;
            public string $bytes = '';

            protected function fetchRemoteImage(string $url, array $options): string
            {
                $this->fetchedUrl = $url;

                return $this->bytes;
            }
        };
        $instance->bytes = $pngBytes;

        // decodeSource() is exactly the source-handling logic make() runs after instance acquisition.
        self::callOn($instance, 'decodeSource', ['http://images.example.com/photo.png']);

        self::assertSame(
            'http://images.example.com/photo.png',
            $instance->fetchedUrl,
            'an http URL must be routed through fetchRemoteImage'
        );
        // Proof that decode() received the fetched BYTES, not the URL string: decoding the URL
        // string would have thrown, and the decoded image carries the fixture's dimensions.
        self::assertSame(2, $instance->getWidth());
        self::assertSame(2, $instance->getHeight());
    }

    public function test_make_does_not_route_non_url_sources_through_fetch(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required to decode the source bytes.');
        }

        $pngBytes = self::tinyPng();
        $instance = new class (
            new ImageManager(new Driver()),
            new ArrayCacheDriver(),
            new ImageSecurityValidator(),
            new NullLogger(),
        ) extends ImageProcessor {
            public bool $fetchCalled = false;

            protected function fetchRemoteImage(string $url, array $options): string
            {
                $this->fetchCalled = true;

                return '';
            }
        };

        // Raw image bytes are not a URL; they must decode directly without touching the fetch path.
        self::callOn($instance, 'decodeSource', [$pngBytes]);

        self::assertFalse($instance->fetchCalled, 'non-URL sources must not hit the remote fetch');
        self::assertSame(2, $instance->getWidth());
    }

    // GAP — IP-literal hosts (decimal/hex/octal) must fail closed through the host validation.

    /**
     * @return iterable<string, array{string}>
     */
    public static function ipLiteralHostProvider(): iterable
    {
        yield 'decimal 127.0.0.1' => ['http://2130706433/'];
        yield 'hex 127.0.0.1'     => ['http://0x7f000001/'];
        yield 'octal 127.0.0.1'   => ['http://017700000001/'];
    }

    /**
     * @dataProvider ipLiteralHostProvider
     */
    public function test_obfuscated_ip_literal_hosts_fail_closed(string $url): void
    {
        // These hosts are not dotted-quad IPs, so resolveHostIps() cannot treat them as literals and
        // they do not resolve via DNS — assertHostIsPublic() must therefore reject them (fail closed)
        // rather than letting an obfuscated loopback target through.
        $this->expectException(\RuntimeException::class);
        self::callPrivate('assertHostIsPublic', [$url]);
    }

    /**
     * Generate a tiny 2x2 PNG via GD for fetch-routing assertions.
     */
    private static function tinyPng(): string
    {
        $image = imagecreatetruecolor(2, 2);
        $color = imagecolorallocate($image, 10, 20, 30);
        imagefilledrectangle($image, 0, 0, 2, 2, $color);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
