<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Integration;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Controllers\UploadController;
use Glueful\Extensions\Media\Contracts\ImageProcessorInterface as MediaImageProcessorInterface;
use Glueful\Extensions\Media\ImageProcessor;
use Glueful\Extensions\Media\MediaProcessor;
use Glueful\Extensions\Media\MediaServiceProvider;
use Glueful\Framework;
use Glueful\Repository\BlobRepository;
use Glueful\Routing\RouteManifest;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Glueful\Uploader\FileUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase D / Task D4 — cross-package INSTALLED verification (spec §"Verification 2").
 *
 * The end-to-end proof that with `glueful/media` installed, core's optional upload
 * seam lights up. A real Framework is booted (file-backed SQLite + local `uploads`
 * disk + `blobs` table + a `request` service carrying a `user`, mirroring the
 * framework B1/B2/B3 upload tests) AND the MediaServiceProvider is applied into the
 * SAME booted container exactly as the framework's extension-loading path would:
 *
 *   1. boot the Framework (core providers register, MediaProcessorInterface UNBOUND);
 *   2. $container->load(MediaServiceProvider::defs()) — last-provider-wins, so
 *      the extension's MediaProcessor OVERRIDES the unbound core seam;
 *   3. new MediaServiceProvider($container)->register()  — merges image.* config;
 *   4. ->boot()  — seeds ImageProcessor::setContext() for the make() static path.
 *
 * Because the CORE StorageProvider's FileUploader / UploadController factories
 * resolve MediaProcessorInterface via `$c->has(...) ? $c->get(...) : null`, binding
 * the extension's MediaProcessor into the same container makes those CORE factories
 * thread the rich processor through — the seam is exercised, not faked.
 */
final class MediaInstalledTest extends TestCase
{
    private string $appPath;
    private string $uploadsRoot;
    private string $dbFile;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required for the installed-verification test.');
        }

        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-media-installed-' . uniqid('', true);
        $this->uploadsRoot = $this->appPath . '/disk';
        $this->dbFile = $this->appPath . '/app.sqlite';
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        mkdir($this->uploadsRoot, 0755, true);

        // File-backed SQLite so every Connection shares one database.
        putenv('DB_DATABASE=' . $this->dbFile);
        putenv('DB_SQLITE_DATABASE=' . $this->dbFile);
        $_ENV['DB_DATABASE'] = $this->dbFile;
        $_ENV['DB_SQLITE_DATABASE'] = $this->dbFile;

        file_put_contents(
            $cfg . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->dbFile . "'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");

        file_put_contents(
            $cfg . '/storage.php',
            "<?php\nreturn ['default' => 'uploads', 'disks' => ['uploads' => "
            . "['driver' => 'local', 'root' => '" . $this->uploadsRoot . "', 'visibility' => 'private']]];\n"
        );
        // access=public so the variant endpoint show() serves without auth.
        file_put_contents(
            $cfg . '/uploads.php',
            "<?php\nreturn ['enabled' => true, 'disk' => 'uploads', 'path_prefix' => '', 'access' => 'public', "
            . "'allowed_types' => ['image/*', 'video/*', 'audio/*'], 'max_size' => 10485760];\n"
        );

        $this->resetSharedConnection();

        // (1) Boot the real Framework — core providers register, seam UNBOUND.
        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();

        // Sanity: before the extension is applied the core seam is unbound.
        self::assertFalse(
            $container->has(MediaProcessorInterface::class),
            'Precondition: MediaProcessorInterface must be UNBOUND before the media provider applies.'
        );

        // (2) Apply the extension provider's DI definitions into the SAME booted
        //     container — last-provider-wins overrides the unbound core seam, and
        //     binds the full image graph (ImageManager/ImageProcessor/interface).
        $container->load(MediaServiceProvider::defs());

        // (3)+(4) Run the provider lifecycle exactly as the extension loader would:
        //     register() merges image.* config; boot() seeds the static context
        //     used by ImageProcessor::make().
        $provider = new MediaServiceProvider($container);
        $provider->register($this->context);
        $provider->boot($this->context);

        $this->runBlobsMigration();

        $request = new Request();
        $request->attributes->set('user', ['uuid' => 'usr123456789']);
        $container->load([
            'request' => new ValueDefinition('request', $request),
        ]);
    }

    protected function tearDown(): void
    {
        ImageProcessor::setContext(null);

        putenv('DB_DATABASE=:memory:');
        putenv('DB_SQLITE_DATABASE');
        $_ENV['DB_DATABASE'] = ':memory:';
        unset($_ENV['DB_SQLITE_DATABASE']);

        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemove($this->appPath);
        }
        parent::tearDown();
    }

    /**
     * #1 Seam bound — the container resolves the CORE seam interface to the
     * EXTENSION's concrete MediaProcessor (last-provider-wins override worked).
     */
    public function testSeamResolvesToExtensionMediaProcessor(): void
    {
        $resolved = $this->context->getContainer()->get(MediaProcessorInterface::class);

        self::assertInstanceOf(
            MediaProcessor::class,
            $resolved,
            'Installed extension must bind the core MediaProcessorInterface to its MediaProcessor.'
        );
    }

    /**
     * #2 + #3 FileUploader (built by the CORE StorageProvider factory) picks up the
     * media processor, proven by behaviour: uploadMedia() yields a NON-null
     * thumb_url written through the UPLOADER'S OWN storage and FULL image
     * dimensions matching the fixture.
     */
    public function testUploadMediaThumbnailThroughUploaderStorageAndFullMetadata(): void
    {
        /** @var FileUploader $uploader */
        $uploader = $this->context->getContainer()->get(FileUploader::class);

        $fixture = $this->makeJpegFixture(200, 150);

        $result = $uploader->uploadMedia(
            [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $fixture,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($fixture),
            ],
            'posts/uuid123',
            ['save_to_blobs' => false]
        );

        // (3a) Thumbnail produced — only possible if the CORE factory threaded the
        //      extension's MediaProcessor into FileUploader's $media seam.
        self::assertNotNull($result['thumb_url'], 'thumb_url must be non-null when media is installed.');

        // (3b) The thumbnail must have been written through the UPLOADER'S OWN
        //      StorageInterface (the local `uploads` disk), NOT a processor-held
        //      storage. Proof: the returned thumb URL carries the {storagePath}/thumbs/
        //      prefix AND the file physically exists under THIS test's local disk root.
        $thumbRel = $this->relPathFromUrl((string) $result['thumb_url']);
        self::assertStringStartsWith(
            'posts/uuid123/thumbs/',
            $thumbRel,
            'Thumbnail path must share the upload storagePath prefix (written via the uploader storage).'
        );
        self::assertFileExists(
            $this->uploadsRoot . '/' . $thumbRel,
            'Thumbnail must physically exist on the uploader\'s local disk root — proving it used the '
            . 'passed-in storage, not a processor-held one.'
        );

        // (3c) Full metadata — the rich extractor reports real dimensions.
        self::assertSame('image', $result['type']);
        self::assertSame(200, $result['width'], 'Rich metadata must report the fixture width.');
        self::assertSame(150, $result['height'], 'Rich metadata must report the fixture height.');

        @unlink($fixture);
    }

    /**
     * #4 Variant endpoint — GET /blobs/{uuid}?width=... through the CORE
     * UploadController (built from the container so it carries the media seam)
     * returns resized image bytes with a correct image mime.
     */
    public function testVariantEndpointReturnsResizedBytes(): void
    {
        // Seed a stored 200x150 image + matching blob row.
        $original = $this->makeJpegBytes(200, 150);
        $relPath = 'posts/variant.jpg';
        $full = $this->uploadsRoot . '/' . $relPath;
        mkdir(dirname($full), 0755, true);
        file_put_contents($full, $original);

        $blobUuid = 'blob00000001';
        \Glueful\Database\Connection::fromContext($this->context)
            ->table('blobs')
            ->insert([
                'uuid' => $blobUuid,
                'name' => 'variant.jpg',
                'mime_type' => 'image/jpeg',
                'size' => strlen($original),
                'url' => $relPath,
                'storage_type' => 'uploads',
                'visibility' => 'public',
                'status' => 'active',
                'created_by' => 'usr123456789',
            ]);

        $c = $this->context->getContainer();
        // Build the controller from the container so it gets the SAME optional
        // MediaProcessorInterface seam the CORE factory resolves.
        $controller = new UploadController(
            $this->context,
            $c->get(FileUploader::class),
            $c->get(BlobRepository::class),
            $c->get(StorageManager::class),
            $c->get(UrlGenerator::class),
            $c->has(MediaProcessorInterface::class) ? $c->get(MediaProcessorInterface::class) : null
        );

        $request = Request::create('/blobs/' . $blobUuid, 'GET', ['width' => 80]);
        $response = $controller->show($request, $blobUuid);

        self::assertSame(200, $response->getStatusCode());
        $mime = (string) $response->headers->get('Content-Type');
        self::assertStringStartsWith('image/', $mime, 'Variant must be served as an image.');

        $body = $this->bodyOf($response);
        $info = getimagesizefromstring($body);
        self::assertNotFalse($info, 'Variant body must be a decodable image.');
        self::assertSame(80, $info[0], 'Variant must be resized to the requested width.');
    }

    /**
     * #5 image() global helper — defined by the EXTENSION and resolving the fluent
     * extension Contracts\ImageProcessorInterface for a real image.
     *
     * The extension's helpers.php defines image() as:
     *   app($context, Contracts\ImageProcessorInterface::class)::make($source)
     *
     * As of media C1 the framework's core src/helpers.php no longer defines a global
     * image() (the stale helper + the dead Services\ImageProcessorInterface /
     * ImageProvider were deleted from the framework, and intervention/image moved
     * here). With core's shadow removed, the extension's image() registers and is the
     * ONE active global helper — so calling image($context, $source) directly now
     * returns the extension's fluent processor. This test asserts that positive
     * behaviour end-to-end (the earlier "known gap" characterisation is obsolete).
     */
    public function testImageHelperReturnsFluentProcessor(): void
    {
        self::assertTrue(function_exists('image'), 'A global image() helper must be defined.');

        $fixture = $this->makeJpegFixture(120, 90);

        // The active global image() is now the EXTENSION's (core no longer shadows
        // it). Its return type is the extension's fluent contract.
        $active = new \ReflectionFunction('image');
        self::assertSame(
            MediaImageProcessorInterface::class,
            (string) $active->getReturnType(),
            'The active global image() must be the extension helper returning its fluent contract '
            . '(core no longer defines image()).'
        );

        // Calling the global helper directly resolves the extension's fluent
        // processor for a real image — proving the D1–D3 graph + helper wiring.
        $processor = image($this->context, $fixture);

        self::assertInstanceOf(
            MediaImageProcessorInterface::class,
            $processor,
            'The global image() helper must yield the fluent ImageProcessorInterface.'
        );
        self::assertInstanceOf(ImageProcessor::class, $processor);

        @unlink($fixture);
    }

    /**
     * #6 Validator from CORE — the extension's ImageProcessor uses the CORE-bound
     * ImageSecurityValidator instance, not a duplicate/extension copy.
     */
    public function testImageProcessorUsesCoreBoundValidator(): void
    {
        $c = $this->context->getContainer();

        /** @var ImageSecurityValidator $coreValidator */
        $coreValidator = $c->get(ImageSecurityValidator::class);
        self::assertInstanceOf(ImageSecurityValidator::class, $coreValidator);

        /** @var ImageProcessor $processor */
        $processor = $c->get(ImageProcessor::class);

        $ref = new \ReflectionProperty(ImageProcessor::class, 'security');
        $ref->setAccessible(true);
        $used = $ref->getValue($processor);

        self::assertSame(
            $coreValidator,
            $used,
            'ImageProcessor must consume the CORE-bound ImageSecurityValidator instance (core owns it).'
        );
    }

    /**
     * Derive the stored-relative path from a thumb/upload URL. The local
     * UrlGenerator produces "/<disk-prefix>/<relPath>" style links; strip back to
     * the portion beginning at the storagePath we uploaded under.
     */
    private function relPathFromUrl(string $url): string
    {
        $needle = 'posts/uuid123/';
        $pos = strpos($url, $needle);
        if ($pos !== false) {
            return substr($url, $pos);
        }

        // Fall back to the URL path component without any leading slash.
        $path = parse_url($url, PHP_URL_PATH);
        return ltrim((string) ($path ?? $url), '/');
    }

    private function bodyOf(Response $response): string
    {
        ob_start();
        $response->sendContent();
        return (string) ob_get_clean();
    }

    private function resetSharedConnection(): void
    {
        $ref = new \ReflectionClass(\Glueful\Repository\BaseRepository::class);
        if ($ref->hasProperty('sharedConnection')) {
            $prop = $ref->getProperty('sharedConnection');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    private function runBlobsMigration(): void
    {
        $schema = \Glueful\Database\Connection::fromContext($this->context)->getSchemaBuilder();

        $schema->createTable('blobs', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('mime_type', 127);
            $table->bigInteger('size');
            $table->string('url', 2048);
            $table->string('storage_type', 20)->default('local');
            $table->string('visibility', 10)->default('private');
            $table->string('status', 20)->default('active');
            $table->string('created_by', 12);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->unique('uuid');
        });
    }

    private function makeJpegFixture(int $width, int $height): string
    {
        $path = $this->appPath . '/fixture-' . uniqid('', true) . '.jpg';
        file_put_contents($path, $this->makeJpegBytes($width, $height));

        return $path;
    }

    private function makeJpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            self::fail('Failed to create GD image fixture.');
        }
        $color = imagecolorallocate($image, 30, 120, 200);
        imagefilledrectangle($image, 0, 0, $width, $height, $color === false ? 0 : $color);

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function recursiveRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveRemove($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
