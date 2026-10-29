<?php

namespace App\DTO\Media;

use Carbon\CarbonImmutable;

/**
 * Immutable description of one stored media item. This is exactly what is
 * persisted in metadata.json, so a tenant directory is self-describing and
 * can be copied between servers without a database.
 */
final class MediaData
{
    public const TYPE_IMAGE = 'image';

    public const TYPE_FILE = 'file';

    public function __construct(
        public readonly string $id,
        public readonly string $tenant,
        public readonly string $module,
        public readonly ?string $entityId,
        public readonly string $mediaType,
        public readonly string $visibility,
        public readonly string $originalFilename,
        public readonly string $storedFilename,
        public readonly string $mimeType,
        public readonly string $originalMimeType,
        public readonly int $sizeBytes,
        public readonly int $originalSizeBytes,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly bool $thumbnail,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $updatedAt,
    ) {}

    public function isImage(): bool
    {
        return $this->mediaType === self::TYPE_IMAGE;
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /** File extension of the stored file (webp for images). */
    public function extension(): string
    {
        return strtolower(pathinfo($this->storedFilename, PATHINFO_EXTENSION));
    }

    /**
     * Return a copy with the given properties changed.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        $current = get_object_vars($this);

        return new self(...array_merge($current, $changes));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant' => $this->tenant,
            'module' => $this->module,
            'entity_id' => $this->entityId,
            'media_type' => $this->mediaType,
            'visibility' => $this->visibility,
            'original_filename' => $this->originalFilename,
            'stored_filename' => $this->storedFilename,
            'mime_type' => $this->mimeType,
            'original_mime_type' => $this->originalMimeType,
            'size_bytes' => $this->sizeBytes,
            'original_size_bytes' => $this->originalSizeBytes,
            'width' => $this->width,
            'height' => $this->height,
            'thumbnail' => $this->thumbnail,
            'created_at' => $this->createdAt->toIso8601String(),
            'updated_at' => $this->updatedAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            tenant: (string) $data['tenant'],
            module: (string) $data['module'],
            entityId: isset($data['entity_id']) && $data['entity_id'] !== '' ? (string) $data['entity_id'] : null,
            mediaType: (string) $data['media_type'],
            visibility: (string) ($data['visibility'] ?? 'public'),
            originalFilename: (string) ($data['original_filename'] ?? ''),
            storedFilename: (string) $data['stored_filename'],
            mimeType: (string) $data['mime_type'],
            originalMimeType: (string) ($data['original_mime_type'] ?? $data['mime_type']),
            sizeBytes: (int) ($data['size_bytes'] ?? 0),
            originalSizeBytes: (int) ($data['original_size_bytes'] ?? 0),
            width: isset($data['width']) ? (int) $data['width'] : null,
            height: isset($data['height']) ? (int) $data['height'] : null,
            thumbnail: (bool) ($data['thumbnail'] ?? false),
            createdAt: CarbonImmutable::parse((string) $data['created_at']),
            updatedAt: CarbonImmutable::parse((string) ($data['updated_at'] ?? $data['created_at'])),
        );
    }
}
