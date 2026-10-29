<?php

namespace App\Repositories\Media;

use App\DTO\Media\MediaData;

/**
 * Lookup and persistence of media records. The first implementation stores
 * metadata.json beside each file; a database-backed implementation can be
 * bound in AppServiceProvider later without touching the controllers.
 */
interface MediaRepositoryInterface
{
    /** Find a media item that belongs to the given tenant. */
    public function find(string $tenant, string $id): ?MediaData;

    /** Find a media item by id regardless of tenant (public view URLs carry no tenant). */
    public function findAnyTenant(string $id): ?MediaData;

    /**
     * List a tenant's media, newest first.
     *
     * @param  array{module?: ?string, entity_id?: ?string, media_type?: ?string, year?: ?int, month?: ?int}  $filters
     * @return array{items: array<int, MediaData>, total: int, page: int, per_page: int}
     */
    public function list(string $tenant, array $filters, int $page, int $perPage): array;

    /** Persist (create or update) the record for a media item. */
    public function save(MediaData $media): void;

    /** Remove the record for a media item. */
    public function forget(MediaData $media): void;
}
