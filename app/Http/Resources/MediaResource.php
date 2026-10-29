<?php

namespace App\Http\Resources;

use App\DTO\Media\MediaData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a media item. Only API URLs are exposed; the
 * physical storage location never leaves the server.
 *
 * @property-read MediaData $resource
 */
class MediaResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->resource;

        return [
            'id' => $media->id,
            'tenant' => $media->tenant,
            'module' => $media->module,
            'entity_id' => $media->entityId,
            'type' => $media->mediaType,
            'format' => $media->extension(),
            'mime_type' => $media->mimeType,
            'visibility' => $media->visibility,
            'original_filename' => $media->originalFilename,
            'original_mime_type' => $media->originalMimeType,
            'size_bytes' => $media->sizeBytes,
            'original_size_bytes' => $media->originalSizeBytes,
            'width' => $media->width,
            'height' => $media->height,
            'thumbnail' => $media->thumbnail,
            'created_at' => $media->createdAt->toIso8601String(),
            'updated_at' => $media->updatedAt->toIso8601String(),
            'view_url' => route('media.show', ['mediaId' => $media->id]),
            'thumbnail_url' => $media->thumbnail ? route('media.thumbnail', ['mediaId' => $media->id]) : null,
            'metadata_url' => route('media.metadata', ['mediaId' => $media->id]),
            'download_url' => route('media.download', ['mediaId' => $media->id]),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function with(Request $request): array
    {
        return ['success' => true];
    }
}
