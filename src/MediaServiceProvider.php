<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Extensions\Media\Contracts\ImageProcessorInterface;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Intervention\Image\ImageManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * DI provider for the rich-media extension.
 *
 * Wires the full media graph so an app with `glueful/media` installed resolves:
 *   - {@see ImageManager} (driver-selected: GD/Imagick with GD fallback),
 *   - {@see ImageProcessorInterface} => {@see ImageProcessor} (+ concrete alias),
 *   - {@see MediaProcessorInterface} => {@see MediaProcessor}.
 *
 * The MediaProcessorInterface binding OVERRIDES core's unbound seam: this
 * provider is applied after the core providers, and Container::load() overwrites
 * by id (last-provider-wins). Without the extension the upload path falls back
 * to dependency-free no-op behaviour.
 *
 * Collaborators owned by CORE are consumed, never re-bound:
 *   - {@see ImageSecurityValidator} (StorageProvider),
 *   - `cache.store` and {@see LoggerInterface} (CoreProvider).
 */
final class MediaServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    /**
     * Strongly-typed service definitions. Must be `defs()` (not the DSL `services()`):
     * the framework's extension loader passes `defs()` entries through as DefinitionInterface
     * objects, whereas `services()` is compiled by the DSL loader which only accepts array specs.
     *
     * @return array<string, DefinitionInterface>
     */
    public static function defs(): array
    {
        return [
            // Intervention ImageManager with driver selection.
            // Lazy: the GD/Imagick driver is only constructed when ImageManager
            // is actually resolved (e.g. via ImageProcessorInterface).
            ImageManager::class => new FactoryDefinition(
                ImageManager::class,
                static function (ContainerInterface $c): ImageManager {
                    $context = $c->get(ApplicationContext::class);
                    $driverType = \function_exists('config')
                        ? (string) config($context, 'image.driver', 'gd')
                        : 'gd';
                    $driver = match ($driverType) {
                        'imagick' => (static function () {
                            if (!\extension_loaded('imagick') || !\class_exists('\\Imagick')) {
                                // fallback to GD
                                if (!\extension_loaded('gd')) {
                                    throw new \RuntimeException('No image driver available');
                                }
                                return new \Intervention\Image\Drivers\Gd\Driver();
                            }
                            return new \Intervention\Image\Drivers\Imagick\Driver();
                        })(),
                        default => (static function () {
                            if (!\extension_loaded('gd')) {
                                throw new \RuntimeException('GD extension not loaded');
                            }
                            return new \Intervention\Image\Drivers\Gd\Driver();
                        })(),
                    };
                    return new ImageManager($driver);
                },
                true,
            ),

            // Image processor interface — resolves the CORE-owned
            // ImageSecurityValidator and `cache.store`/logger.
            //
            // NON-shared (third arg false): ImageProcessor carries mutable per-image state
            // (image bytes, operations, cacheKey), so a shared instance would let two injection
            // points processing different images stomp each other — the same hazard the static
            // factories avoid via fresh(). Every resolution therefore returns a clean instance.
            ImageProcessorInterface::class => new FactoryDefinition(
                ImageProcessorInterface::class,
                static function (ContainerInterface $c): ImageProcessor {
                    $context = $c->get(ApplicationContext::class);
                    $getConfig = static fn(string $key): array => \function_exists('config')
                        ? (array) config($context, $key, [])
                        : [];
                    $config = [
                        'optimization' => $getConfig('image.optimization'),
                        'security' => $getConfig('image.security'),
                        'cache' => $getConfig('image.cache'),
                        'features' => $getConfig('image.features'),
                        'defaults' => $getConfig('image.defaults'),
                        'performance' => $getConfig('image.performance'),
                        'monitoring' => $getConfig('image.monitoring'),
                    ];

                    return new ImageProcessor(
                        $c->get(ImageManager::class),
                        $c->get('cache.store'),
                        // CORE-owned validator (StorageProvider) — never re-bound here.
                        $c->get(ImageSecurityValidator::class),
                        $c->get(LoggerInterface::class),
                        $config,
                    );
                },
                false,
            ),

            // Concrete id aliases the interface. AliasDefinition is itself non-shared and
            // delegates to the target, so resolving ImageProcessor::class yields a FRESH
            // instance per call too (matching the non-shared interface binding above).
            ImageProcessor::class => new AliasDefinition(
                ImageProcessor::class,
                ImageProcessorInterface::class,
            ),

            // Core upload seam — overrides the unbound default (last-provider-wins).
            // Composes the metadata extractor; storage is passed per-call, never held.
            MediaProcessorInterface::class => new FactoryDefinition(
                MediaProcessorInterface::class,
                static function (ContainerInterface $c): MediaProcessor {
                    $context = $c->get(ApplicationContext::class);
                    return new MediaProcessor(
                        new MediaMetadataExtractor(),
                        $context,
                    );
                },
                true,
            ),
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // Merge image defaults so IMAGE_* env works once the extension is installed.
        $this->mergeConfig('image', require __DIR__ . '/../config/image.php');
    }

    public function boot(ApplicationContext $context): void
    {
        // Seed the static default context used by ImageProcessor::make()
        // (replaces the framework's former Framework.php poke).
        ImageProcessor::setContext($context);
    }
}
