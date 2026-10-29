<?php

namespace App\Services\Media;

use App\DTO\Media\MediaData;
use App\Exceptions\MediaException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Single place that knows the on-disk layout and validates every path
 * segment that originates from a request. Layout:
 *
 *   tenants/{tenant}/{images|files}/{module}/{YYYY}/{MM}/{media-id}/
 */
class MediaPathService
{
    public const ID_PATTERN = '/^[0-9a-z]{26}$/';

    public function disk(): Filesystem
    {
        return Storage::disk(config('media.disk', 'media'));
    }

    /** Absolute storage root (never exposed to clients). */
    public function root(): string
    {
        return rtrim($this->disk()->path(''), DIRECTORY_SEPARATOR);
    }

    public function tenantsDirectory(): string
    {
        return trim((string) config('media.tenants_directory', 'tenants'), '/');
    }

    public function tenantDirectory(string $tenant): string
    {
        return $this->tenantsDirectory().'/'.$this->assertTenant($tenant);
    }

    public function branch(string $mediaType): string
    {
        return $mediaType === MediaData::TYPE_IMAGE ? 'images' : 'files';
    }

    /** Relative directory of a media item inside the disk. */
    public function mediaDirectory(MediaData $media): string
    {
        return implode('/', [
            $this->tenantDirectory($media->tenant),
            $this->branch($media->mediaType),
            $this->assertModule($media->module),
            $media->createdAt->format('Y'),
            $media->createdAt->format('m'),
            $this->assertMediaId($media->id),
        ]);
    }

    /** Absolute directory of a media item. */
    public function absoluteMediaDirectory(MediaData $media): string
    {
        return $this->absolute($this->mediaDirectory($media));
    }

    public function absolute(string $relative): string
    {
        return $this->disk()->path(ltrim($relative, '/'));
    }

    /** Absolute path of a file inside the media directory. */
    public function filePath(MediaData $media, string $filename): string
    {
        return $this->absoluteMediaDirectory($media).DIRECTORY_SEPARATOR.$this->assertFilename($filename);
    }

    public function assertTenant(?string $tenant): string
    {
        if (! is_string($tenant) || ! preg_match(config('media.tenant.pattern'), $tenant)) {
            throw MediaException::badRequest('Invalid client code.');
        }

        return $tenant;
    }

    public function assertModule(?string $module): string
    {
        if (! is_string($module) || ! preg_match(config('media.module.pattern'), $module)) {
            throw MediaException::validation('Invalid module.', ['module' => ['The module may only contain a-z, 0-9, "-" and "_" (max 50).']]);
        }

        return $module;
    }

    public function assertEntityId(?string $entityId): ?string
    {
        if ($entityId === null || $entityId === '') {
            return null;
        }

        if (! preg_match(config('media.entity_id.pattern'), $entityId)) {
            throw MediaException::validation('Invalid entity id.', ['entity_id' => ['The entity id may only contain letters, digits, ".", "-" and "_" (max 100).']]);
        }

        return $entityId;
    }

    public function assertMediaId(?string $id): string
    {
        if (! is_string($id) || ! preg_match(self::ID_PATTERN, $id)) {
            throw MediaException::notFound();
        }

        return $id;
    }

    public function assertVisibility(?string $visibility): string
    {
        $visibility = $visibility ?: config('media.visibility.default', 'public');

        if (! in_array($visibility, config('media.visibility.allowed', ['public', 'private']), true)) {
            throw MediaException::validation('Invalid visibility.', ['visibility' => ['Visibility must be public or private.']]);
        }

        return $visibility;
    }

    private function assertFilename(string $filename): string
    {
        if ($filename === '' || $filename !== basename($filename) || ! preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            throw MediaException::server('Invalid storage filename.');
        }

        return $filename;
    }

    /**
     * True when the (existing) absolute path resolves inside the tenant's
     * storage tree. Used as a last line of defence before deleting anything.
     */
    public function isInsideTenant(string $absolutePath, string $tenant): bool
    {
        $real = realpath($absolutePath);
        $tenantRoot = realpath($this->absolute($this->tenantDirectory($tenant)));

        if ($real === false || $tenantRoot === false) {
            return false;
        }

        return str_starts_with($real.DIRECTORY_SEPARATOR, $tenantRoot.DIRECTORY_SEPARATOR);
    }

    /** Remove the storage root from a message so logs never leak server paths. */
    public function redact(string $message): string
    {
        $root = $this->root();

        return $root !== '' ? str_replace($root, '[media-root]', $message) : $message;
    }
}
