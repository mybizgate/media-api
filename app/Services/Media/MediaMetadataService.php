<?php

namespace App\Services\Media;

use App\DTO\Media\MediaData;
use App\Exceptions\MediaException;
use Throwable;

/**
 * Reads and writes the metadata.json sidecar that lives beside every media
 * file. Writes are atomic (temp file + rename).
 */
class MediaMetadataService
{
    public function filename(): string
    {
        return (string) config('media.metadata_filename', 'metadata.json');
    }

    public function write(string $absoluteDirectory, MediaData $media): void
    {
        $target = $absoluteDirectory.DIRECTORY_SEPARATOR.$this->filename();
        $temp = $target.'.'.bin2hex(random_bytes(4)).'.tmp';

        $json = json_encode($media->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || @file_put_contents($temp, $json.PHP_EOL, LOCK_EX) === false || ! @rename($temp, $target)) {
            @unlink($temp);

            throw MediaException::server('The media metadata could not be written.');
        }
    }

    public function read(string $absoluteDirectory): ?MediaData
    {
        $path = $absoluteDirectory.DIRECTORY_SEPARATOR.$this->filename();

        if (! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

            return is_array($data) ? MediaData::fromArray($data) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
