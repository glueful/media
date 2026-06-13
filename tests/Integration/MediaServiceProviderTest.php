<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Container\Loader\DefaultServicesLoader;
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
 * MediaServiceProvider::defs() AFTER. Container::load() overwrites by id,
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
        $container->load(MediaServiceProvider::defs());

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

    public function testImageProcessorInterfaceResolvesFreshPerCall(): void
    {
        $context = $this->context();
        $container = $this->container($context, new ImageSecurityValidator([]));

        $first = $container->get(ImageProcessorInterface::class);
        $second = $container->get(ImageProcessorInterface::class);
        $viaConcrete = $container->get(ImageProcessor::class);

        self::assertInstanceOf(ImageProcessor::class, $first);
        self::assertInstanceOf(ImageProcessor::class, $viaConcrete);

        // The binding is NON-shared: ImageProcessor carries mutable per-image state, so a shared
        // instance would let two consumers processing different images stomp each other. Every
        // resolution — interface OR concrete-alias — must therefore be a distinct instance.
        self::assertNotSame(
            $first,
            $second,
            'ImageProcessorInterface must resolve a fresh instance per call (non-shared)'
        );
        self::assertNotSame(
            $first,
            $viaConcrete,
            'The concrete alias must also resolve fresh (AliasDefinition delegates to a non-shared target)'
        );
    }

    public function testProviderDoesNotRebindImageSecurityValidator(): void
    {
        // The provider's services() array must NOT declare the validator key:
        // core (StorageProvider) owns it.
        self::assertArrayNotHasKey(
            ImageSecurityValidator::class,
            MediaServiceProvider::defs(),
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

    /**
     * Discovery-path guard. Loads the provider the way the framework's extension discovery
     * actually does (ContainerFactory::loadExtensionDefinitions): a `defs()` map passes through
     * as DefinitionInterface objects, while a `services()` map is compiled by
     * DefaultServicesLoader — which REJECTS non-array specs.
     *
     * The other tests use Container::load(), which accepts Definition objects directly, so they
     * would NOT catch typed Definition objects mistakenly returned from `services()` (they belong
     * in `defs()`). This test fails loudly on that regression — the loader throws
     * "Service '<id>' must be an array".
     */
    public function testLoadsThroughExtensionDiscoveryDispatch(): void
    {
        $provider = MediaServiceProvider::class;

        if (method_exists($provider, 'defs')) {
            $defs = (array) $provider::defs();
        } else {
            $defs = (new DefaultServicesLoader())->load($provider::services(), $provider, false);
        }

        self::assertNotEmpty($defs);
        self::assertArrayHasKey(
            ImageProcessorInterface::class,
            $defs,
            'Provider must declare the ImageProcessorInterface binding'
        );
        foreach ($defs as $id => $def) {
            self::assertInstanceOf(
                DefinitionInterface::class,
                $def,
                "Definition for '{$id}' must be a DefinitionInterface after discovery-path loading"
            );
        }
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
