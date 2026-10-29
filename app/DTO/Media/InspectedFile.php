<?php

namespace App\DTO\Media;

/** Result of server-side validation of an uploaded file. */
final class InspectedFile
{
    public function __construct(
        public readonly string $mediaType,
        public readonly string $detectedMimeType,
        public readonly string $servedMimeType,
        public readonly string $extension,
        public readonly int $sizeBytes,
        public readonly string $originalFilename,
    ) {}

    public function isImage(): bool
    {
        return $this->mediaType === MediaData::TYPE_IMAGE;
    }
}
