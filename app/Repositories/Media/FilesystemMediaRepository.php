<?php

namespace App\Repositories\Media;

use App\DTO\Media\MediaData;
use App\Services\Media\MediaMetadataService;
use App\Services\Media\MediaPathService;

/**
 * Filesystem implementation: the metadata.json sidecar is the record and the
 * directory layout is the index. Lookups by id use a bounded glob
 * (tenants/{tenant}/{images,files}/{module}/{YYYY}/{MM}/{id}); every segment
 * that reaches the glob has already been validated against a strict pattern.
 */
class FilesystemMediaRepository implements MediaRepositoryInterface
{
    public function __construct(
        private readonly MediaPathService $paths,
        private readonly MediaMetadataService $metadata,
    ) {}

    public function find(string $tenant, string $id): ?MediaData
    {
        $tenant = $this->paths->assertTenant($tenant);
        $id = $this->paths->assertMediaId($id);

        return $this->firstMatching($this->paths->tenantDirectory($tenant).'/{images,files}/*/*/*/'.$id, $id, $tenant);
    }

    public function findAnyTenant(string $id): ?MediaData
    {
        $id = $this->paths->assertMediaId($id);

        return $this->firstMatching($this->paths->tenantsDirectory().'/*/{images,files}/*/*/*/'.$id, $id, null);
    }

    public function list(string $tenant, array $filters, int $page, int $perPage): array
    {
        $tenant = $this->paths->assertTenant($tenant);

        $branch = match ($filters['media_type'] ?? null) {
            MediaData::TYPE_IMAGE => 'images',
            MediaData::TYPE_FILE => 'files',
            default => '{images,files}',
        };

        $module = isset($filters['module']) ? $this->paths->assertModule($filters['module']) : '*';
        $year = isset($filters['year']) ? sprintf('%04d', (int) $filters['year']) : '*';
        $month = isset($filters['month']) ? sprintf('%02d', (int) $filters['month']) : '*';

        $pattern = $this->paths->absolute(implode('/', [
            $this->paths->tenantDirectory($tenant), $branch, $module, $year, $month, '*', $this->metadata->filename(),
        ]));

        $entityId = $this->paths->assertEntityId($filters['entity_id'] ?? null);

        $items = [];

        foreach ($this->glob($pattern) as $file) {
            $media = $this->metadata->read(dirname($file));

            if ($media === null || $media->tenant !== $tenant) {
                continue;
            }

            if ($entityId !== null && $media->entityId !== $entityId) {
                continue;
            }

            $items[] = $media;
        }

        usort($items, fn (MediaData $a, MediaData $b) => [$b->createdAt->getTimestamp(), $b->id] <=> [$a->createdAt->getTimestamp(), $a->id]);

        $total = count($items);
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function save(MediaData $media): void
    {
        $this->metadata->write($this->paths->absoluteMediaDirectory($media), $media);
    }

    public function forget(MediaData $media): void
    {
        $file = $this->paths->absoluteMediaDirectory($media).DIRECTORY_SEPARATOR.$this->metadata->filename();

        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function firstMatching(string $relativePattern, string $id, ?string $tenant): ?MediaData
    {
        foreach ($this->glob($this->paths->absolute($relativePattern), GLOB_ONLYDIR) as $directory) {
            $media = $this->metadata->read($directory);

            if ($media === null || $media->id !== $id) {
                continue;
            }

            if ($tenant !== null && $media->tenant !== $tenant) {
                continue;
            }

            return $media;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function glob(string $pattern, int $flags = 0): array
    {
        $matches = glob($pattern, $flags | GLOB_BRACE | GLOB_NOSORT);

        return $matches === false ? [] : $matches;
    }
}
