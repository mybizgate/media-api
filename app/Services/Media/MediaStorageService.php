<?php

namespace App\Services\Media;

use App\DTO\Media\InspectedFile;
use App\DTO\Media\MediaData;
use App\Exceptions\MediaException;
use App\Repositories\Media\MediaRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates upload, replace, assign and delete. New content is always built in a
 * staging directory next to its final location and moved into place with a
 * rename, so a failed upload never leaves a half-written media item behind
 * and a replacement only discards the old files after the new ones exist.
 */
class MediaStorageService
{
    public function __construct(
        private readonly MediaRepositoryInterface $repository,
        private readonly MediaPathService $paths,
        private readonly MediaFileValidator $validator,
        private readonly ImageProcessingService $images,
        private readonly MediaMetadataService $metadata,
    ) {}

    public function upload(string $tenant, UploadedFile $file, string $module, ?string $entityId, ?string $visibility): MediaData
    {
        $tenant = $this->paths->assertTenant($tenant);
        $module = $this->paths->assertModule($module);
        $entityId = $this->paths->assertEntityId($entityId);
        $visibility = $this->paths->assertVisibility($visibility);

        $inspected = $this->validator->inspect($file);
        $now = CarbonImmutable::now();

        $media = new MediaData(
            id: $this->newId(),
            tenant: $tenant,
            module: $module,
            entityId: $entityId,
            mediaType: $inspected->mediaType,
            visibility: $visibility,
            originalFilename: $inspected->originalFilename,
            storedFilename: $this->storedFilename($inspected),
            mimeType: $inspected->servedMimeType,
            originalMimeType: $inspected->detectedMimeType,
            sizeBytes: 0,
            originalSizeBytes: $inspected->sizeBytes,
            width: null,
            height: null,
            thumbnail: false,
            createdAt: $now,
            updatedAt: $now,
        );

        try {
            $final = $this->paths->absoluteMediaDirectory($media);
            $staging = $this->stagingDirectory($final);

            $media = $this->buildContent($media, $file, $inspected, $staging);

            File::ensureDirectoryExists(dirname($final));

            if (! @rename($staging, $final)) {
                throw MediaException::server();
            }

            $this->repository->save($media);
        } catch (Throwable $e) {
            $this->discard($staging ?? null);
            $this->logFailure('upload', $media, $e);

            throw $e instanceof MediaException ? $e : MediaException::server();
        }

        return $media;
    }

    public function replace(MediaData $existing, UploadedFile $file, ?string $visibility = null): MediaData
    {
        $inspected = $this->validator->inspect($file);

        $media = $existing->with([
            'mediaType' => $inspected->mediaType,
            'visibility' => $visibility !== null ? $this->paths->assertVisibility($visibility) : $existing->visibility,
            'originalFilename' => $inspected->originalFilename,
            'storedFilename' => $this->storedFilename($inspected),
            'mimeType' => $inspected->servedMimeType,
            'originalMimeType' => $inspected->detectedMimeType,
            'originalSizeBytes' => $inspected->sizeBytes,
            'sizeBytes' => 0,
            'width' => null,
            'height' => null,
            'thumbnail' => false,
            'updatedAt' => CarbonImmutable::now(),
        ]);

        $oldDirectory = $this->paths->absoluteMediaDirectory($existing);
        $final = $this->paths->absoluteMediaDirectory($media);
        $staging = null;
        $retired = null;

        try {
            $staging = $this->stagingDirectory($final);
            $media = $this->buildContent($media, $file, $inspected, $staging);

            File::ensureDirectoryExists(dirname($final));

            // Swap: retire the old directory, move the new one into place, then
            // remove the retired copy. The old media survives any earlier failure.
            if (is_dir($oldDirectory)) {
                $retired = $oldDirectory.'.old-'.Str::lower(Str::random(8));

                if (! @rename($oldDirectory, $retired)) {
                    throw MediaException::server('The existing media could not be replaced.');
                }
            }

            if (! @rename($staging, $final)) {
                if ($retired !== null) {
                    @rename($retired, $oldDirectory);
                }

                throw MediaException::server('The existing media could not be replaced.');
            }

            $staging = null;

            if ($final !== $oldDirectory) {
                $this->repository->forget($existing);
            }

            $this->repository->save($media);
        } catch (Throwable $e) {
            $this->discard($staging);
            $this->logFailure('replace', $media, $e);

            throw $e instanceof MediaException ? $e : MediaException::server();
        }

        if ($retired !== null) {
            $this->discard($retired);
        }

        return $media;
    }

    /**
     * Re-point an existing media item at a business record (or clear the link
     * with null). Only metadata.json changes; files are untouched.
     */
    public function assign(MediaData $existing, ?string $entityId): MediaData
    {
        $media = $existing->with([
            'entityId' => $this->paths->assertEntityId($entityId),
            'updatedAt' => CarbonImmutable::now(),
        ]);

        try {
            $this->repository->save($media);
        } catch (Throwable $e) {
            $this->logFailure('assign', $media, $e);

            throw $e instanceof MediaException ? $e : MediaException::server('The media could not be updated.');
        }

        return $media;
    }

    public function delete(MediaData $media): void
    {
        $directory = $this->paths->absoluteMediaDirectory($media);

        if (! is_dir($directory)) {
            $this->repository->forget($media);

            return;
        }

        if (! $this->paths->isInsideTenant($directory, $media->tenant)) {
            throw MediaException::server('Refusing to delete outside the tenant storage root.');
        }

        $this->repository->forget($media);

        foreach ($this->managedFiles($media) as $filename) {
            $file = $directory.DIRECTORY_SEPARATOR.$filename;

            if (is_file($file)) {
                @unlink($file);
            }
        }

        // Only the media's own directory is removed; parents are left untouched.
        @rmdir($directory);

        if (is_dir($directory)) {
            Log::warning('media.delete_incomplete', ['tenant' => $media->tenant, 'id' => $media->id]);

            throw MediaException::server('The media directory could not be removed.');
        }
    }

    /** Absolute path to the primary stored file. */
    public function contentPath(MediaData $media): string
    {
        return $this->paths->filePath($media, $media->storedFilename);
    }

    /** Absolute path to the thumbnail, or null when the media has none. */
    public function thumbnailPath(MediaData $media): ?string
    {
        if (! $media->isImage() || ! $media->thumbnail) {
            return null;
        }

        return $this->paths->filePath($media, (string) config('media.thumbnail.filename'));
    }

    /** Filename offered to the browser on download (display only, never a path). */
    public function downloadFilename(MediaData $media): string
    {
        $base = pathinfo($media->originalFilename, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\pL\pN ._()-]+/u', '_', $base), ' ._');

        if ($base === '') {
            $base = $media->id;
        }

        return mb_substr($base, 0, 150).'.'.$media->extension();
    }

    private function buildContent(MediaData $media, UploadedFile $file, InspectedFile $inspected, string $staging): MediaData
    {
        File::ensureDirectoryExists($staging);

        if ($inspected->isImage()) {
            return $this->buildImage($media, $file, $inspected, $staging);
        }

        $target = $staging.DIRECTORY_SEPARATOR.$media->storedFilename;

        if (! @copy($file->getRealPath(), $target)) {
            throw MediaException::server();
        }

        @chmod($target, 0644);

        $media = $media->with(['sizeBytes' => (int) filesize($target)]);
        $this->metadata->write($staging, $media);

        return $media;
    }

    private function buildImage(MediaData $media, UploadedFile $file, InspectedFile $inspected, string $staging): MediaData
    {
        $source = $this->images->decode($file->getRealPath(), $inspected->detectedMimeType);

        try {
            $main = $this->images->toWebp(
                $source,
                (int) config('media.image.max_width'),
                (int) config('media.image.max_height'),
                (int) config('media.image.webp_quality'),
            );

            $thumb = $this->images->toWebp(
                $source,
                (int) config('media.thumbnail.max_width'),
                (int) config('media.thumbnail.max_height'),
                (int) config('media.thumbnail.webp_quality'),
            );
        } finally {
            $this->images->free($source);
        }

        $this->writeBinary($staging.DIRECTORY_SEPARATOR.$media->storedFilename, $main->binary);
        $this->writeBinary($staging.DIRECTORY_SEPARATOR.config('media.thumbnail.filename'), $thumb->binary);

        $media = $media->with([
            'sizeBytes' => $main->size(),
            'width' => $main->width,
            'height' => $main->height,
            'thumbnail' => true,
        ]);

        $this->metadata->write($staging, $media);

        return $media;
    }

    private function writeBinary(string $path, string $binary): void
    {
        if (@file_put_contents($path, $binary, LOCK_EX) === false) {
            throw MediaException::server();
        }

        @chmod($path, 0644);
    }

    private function storedFilename(InspectedFile $inspected): string
    {
        return $inspected->isImage()
            ? (string) config('media.image.filename')
            : config('media.file.basename').'.'.$inspected->extension;
    }

    /**
     * @return array<int, string>
     */
    private function managedFiles(MediaData $media): array
    {
        return array_values(array_unique([
            $media->storedFilename,
            (string) config('media.thumbnail.filename'),
            $this->metadata->filename(),
        ]));
    }

    private function newId(): string
    {
        return Str::lower((string) Str::ulid());
    }

    private function stagingDirectory(string $final): string
    {
        return $final.'.staging-'.Str::lower(Str::random(8));
    }

    private function discard(?string $directory): void
    {
        if ($directory !== null && is_dir($directory)) {
            File::deleteDirectory($directory);
        }
    }

    private function logFailure(string $action, MediaData $media, Throwable $e): void
    {
        Log::warning('media.'.$action.'_failed', [
            'tenant' => $media->tenant,
            'module' => $media->module,
            'id' => $media->id,
            'original_filename' => $media->originalFilename,
            'error' => $this->paths->redact($e->getMessage()),
            'exception' => $e::class,
        ]);
    }
}
