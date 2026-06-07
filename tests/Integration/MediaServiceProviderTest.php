<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Extensions\Media\Contracts\ImageProcessorInterface;
use Glueful\Extensions\Media\ImageProcessor;
use Glueful\Extensions\Media\MediaProcessor;
use Glueful\Extensions\Media\MediaServiceProvider;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Proves the media extension's provider definitions wire the full media graph
 * and override core's unbound MediaProcessorInterface seam (last-provider-wins),
 * exactly as they would when `glueful/media` is installed and its provider is
 * applied after the core providers.
 *
 * Harness: build the real framework Container seeded with the CORE-owned
 * collaborators the media factories depend on (ApplicationContext, cache.store,
 * LoggerInterface, ImageSecurityValidator), then load
 * MediaServiceProvider::services() AFTER. Container::load() overwrites by id,
 * mirroring provider-registration order.
 */
final class MediaServiceProviderTest extends TestCase
{
    private function context(): ApplicationContext
    {
        return new ApplicationContext(sys_get_temp_dir(), 'testing');
    }

    /**
     * Build a container with the CORE collaborators pre-bound, then layer the
     * extension provider on top. The core ImageSecurityValidator binding is
     * captured so the test can prove the provider does NOT re-bind it.
     */
    private function container(ApplicationContext $context, ImageSecurityValidator $coreValidator): Container
    {
        $container = new Container([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            // CoreProvider owns these — the media factories consume, never bind them.
            // Real array-backed CacheStore (CACHE_DRIVER=array, no Redis needed).
            'cache.store' => new ValueDefinition(
                'cache.store',
                \Glueful\Cache\CacheFactory::create('array')
            ),
            LoggerInterface::class => new ValueDefinition(LoggerInterface::class, new NullLogger()),
            ImageSecurityValidator::class => new ValueDefinition(
                ImageSecurityValidator::class,
                $coreValidator
            ),
        ]);
        $context->setContainer($container);

        // Extension provider definitions applied AFTER core (last-provider-wins).
        $container->load(MediaServiceProvider::services());

        return $container;
    }

    public function testProviderOverridesUnboundMediaProcessorSeam(): void
    {
        $context = $this->context();
        $container = $this->container($context, new ImageSecurityValidator([]));

        $resolved = $container->get(MediaProcessorInterface::class);

        self::assertInstanceOf(
            MediaProcessor::class,
            $resolved,
            'Media provider must bind MediaProcessorInterface to the concrete MediaProcessor'
        );
    }

    public function testMediaProcessorSeamIsShared(): void
    {
        $context = $this->context();
        $container = $this->container($context, new ImageSecurityValidator([]));

        self::assertSame(
            $container->get(MediaProcessorInterface::class),
            $container->get(MediaProcessorInterface::class),
            'MediaProcessorInterface factory must be shared'
        );
    }

    public function testImageProcessorInterfaceAndConcreteShareOneInstance(): void
    {
        $context = $this->context();
        $container = $this->container($context, new ImageSecurityValidator([]));

        $viaInterface = $container->get(ImageProcessorInterface::class);
        $viaConcrete = $container->get(ImageProcessor::class);

        self::assertInstanceOf(ImageProcessor::class, $viaInterface);
        // make() resolves the CONCRETE id (app($context, ImageProcessor::class));
        // the alias must funnel it to the same shared interface instance.
        self::assertSame(
            $viaInterface,
            $viaConcrete,
            'ImageProcessor alias + shared interface factory must yield one shared instance'
        );
    }

    public function testProviderDoesNotRebindImageSecurityValidator(): void
    {
        // The provider's services() array must NOT declare the validator key:
        // core (StorageProvider) owns it.
        self::assertArrayNotHasKey(
            ImageSecurityValidator::class,
            MediaServiceProvider::services(),
            'Provider must not re-bind ImageSecurityValidator — core owns it'
        );

        // And resolving it after loading the provider yields the CORE instance.
        $context = $this->context();
        $coreValidator = new ImageSecurityValidator([]);
        $container = $this->container($context, $coreValidator);

        self::assertSame(
            $coreValidator,
            $container->get(ImageSecurityValidator::class),
            'Validator must remain the core-bound instance after the media provider loads'
        );
    }

    public function testRegisterMergesImageConfig(): void
    {
        $context = $this->context();
        $container = new Container([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
        ]);
        $context->setContainer($container);

        $provider = new MediaServiceProvider($container);
        $provider->register($context);

        // image.* config must resolve after register() (proves mergeConfig ran).
        self::assertNotNull(
            config($context, 'image.driver'),
            'register() must merge the extension image config so image.* keys resolve'
        );
    }
}
