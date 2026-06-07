<?php

declare(strict_types=1);

namespace Glueful\Extensions\Media;

use Glueful\Uploader\Storage\StorageInterface;

/**
 * No-op storage used only where a {@see StorageInterface} is structurally
 * required but never exercised — e.g. probing {@see ThumbnailGenerator} for
 * MIME-type support, which reads config only and never writes.
 *
 * It is intentionally never used for real reads/writes; every method that would
 * touch a disk throws so misuse surfaces loudly during development.
 *
 * @internal
 */
final class NullStorage implements StorageInterface
{
    public function store(string $sourcePath, string $destinationPath): string
    {
        throw new \LogicException('NullStorage cannot store files.');
    }

    public function storeContent(string $content, string $destinationPath): string
    {
        throw new \LogicException('NullStorage cannot store content.');
    }

    public function getUrl(string $path): string
    {
        throw new \LogicException('NullStorage has no URLs.');
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function delete(string $path): bool
    {
        return false;
    }

    public function getSignedUrl(string $path, int $expiry = 3600): string
    {
        throw new \LogicException('NullStorage has no signed URLs.');
    }
}
