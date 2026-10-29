<?php

namespace App\Services\Media;

use App\DTO\Media\InspectedFile;
use App\DTO\Media\MediaData;
use App\Exceptions\MediaException;
use Illuminate\Http\UploadedFile;

/**
 * Server-side upload hardening: MIME sniffing of the actual bytes, extension
 * allow/deny lists, per-type size limits. The browser supplied MIME type is
 * never trusted.
 */
class MediaFileValidator
{
    public function __construct(private readonly ImageProcessingService $images) {}

    public function inspect(UploadedFile $file): InspectedFile
    {
        if (! $file->isValid()) {
            throw MediaException::validation('The file failed to upload.', ['file' => [$file->getErrorMessage()]]);
        }

        $originalName = $this->sanitizeOriginalName($file->getClientOriginalName());
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $size = (int) $file->getSize();

        if ($extension === '' || in_array($extension, config('media.blocked_extensions', []), true)) {
            throw MediaException::validation('This file type is not allowed.', ['file' => ['Files with the ".'.$extension.'" extension are not accepted.']]);
        }

        // Detected from the file contents (finfo), not from the request.
        $detected = strtolower((string) ($file->getMimeType() ?: 'application/octet-stream'));

        $imageTypes = $this->allowedImageTypes();

        if (isset($imageTypes[$detected])) {
            $this->assertExtension($extension, $imageTypes[$detected]);
            $this->assertSize($size, (int) config('media.image.max_size_kb'), 'image');

            if ($this->images->inspect($file->getRealPath()) === null) {
                throw MediaException::validation('The image could not be read.', ['file' => ['The uploaded image is corrupt or unsupported.']]);
            }

            return new InspectedFile(MediaData::TYPE_IMAGE, $detected, 'image/webp', 'webp', $size, $originalName);
        }

        $fileTypes = (array) config('media.file.allowed', []);

        if (isset($fileTypes[$detected])) {
            $this->assertExtension($extension, $fileTypes[$detected]);
            $this->assertSize($size, (int) config('media.file.max_size_kb'), 'file');

            return new InspectedFile(MediaData::TYPE_FILE, $detected, $this->canonicalMime($extension, $detected), $extension, $size, $originalName);
        }

        throw MediaException::validation('Unsupported file type.', ['file' => ['Detected type "'.$detected.'" is not accepted. Allowed: JPEG, PNG, WebP, PDF, TXT, CSV, DOC, DOCX, XLS, XLSX.']]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function allowedImageTypes(): array
    {
        $types = (array) config('media.image.allowed', []);

        if (config('media.image.allow_avif') && $this->images->supportsAvif()) {
            $types['image/avif'] = ['avif'];
        }

        return $types;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function assertExtension(string $extension, array $allowed): void
    {
        if (! in_array($extension, $allowed, true)) {
            throw MediaException::validation('The file extension does not match its content.', ['file' => ['Extension ".'.$extension.'" does not match the detected file type.']]);
        }
    }

    private function assertSize(int $size, int $maxKb, string $kind): void
    {
        if ($size <= 0) {
            throw MediaException::validation('The file is empty.', ['file' => ['The uploaded file is empty.']]);
        }

        if ($size > $maxKb * 1024) {
            throw MediaException::validation('The file is too large.', ['file' => ['The '.$kind.' may not be larger than '.$maxKb.' KB.']]);
        }
    }

    /** The MIME type used when serving a document, derived from its extension. */
    private function canonicalMime(string $extension, string $detected): string
    {
        foreach ((array) config('media.file.allowed', []) as $mime => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $mime;
            }
        }

        return $detected;
    }

    /** Keep the original name for display only; it is never used as a path. */
    public function sanitizeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'upload';
        }

        return mb_substr($name, 0, 255);
    }
}
