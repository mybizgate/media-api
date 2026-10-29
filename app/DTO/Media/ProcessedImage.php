<?php

namespace App\DTO\Media;

/** An encoded WebP image produced by the ImageProcessingService. */
final class ProcessedImage
{
    public function __construct(
        public readonly string $binary,
        public readonly int $width,
        public readonly int $height,
    ) {}

    public function size(): int
    {
        return strlen($this->binary);
    }
}
