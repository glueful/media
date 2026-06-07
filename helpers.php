<?php

declare(strict_types=1);

if (!function_exists('image')) {
    /**
     * Create image processor instance
     *
     * Convenience function to create and configure image processor instances.
     * Returns the ImageProcessorInterface for fluent image operations.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $source Image source path or URL
     * @return \Glueful\Extensions\Media\Contracts\ImageProcessorInterface
     */
    function image(
        \Glueful\Bootstrap\ApplicationContext $context,
        string $source
    ): \Glueful\Extensions\Media\Contracts\ImageProcessorInterface {
        $processor = app($context, \Glueful\Extensions\Media\Contracts\ImageProcessorInterface::class);
        return $processor::make($source);
    }
}
