<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    public const TENANT = 'lzhub';

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every test runs against a throw-away disk under storage/framework/testing;
        // the real MEDIA_STORAGE_PATH is never touched.
        Storage::fake('media');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** Absolute root of the faked media disk. */
    protected function mediaRoot(): string
    {
        return rtrim(Storage::disk('media')->path(''), '/');
    }

    /**
     * @return array<string, string>
     */
    protected function tenantHeaders(string $tenant = self::TENANT): array
    {
        return ['Accept' => 'application/json', 'X-Client-Code' => $tenant];
    }

    /** A real GD-generated image with the given format and dimensions. */
    protected function imageFile(string $format = 'jpeg', int $width = 800, int $height = 600, ?string $name = null): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 120, 200));
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 240, 200, 40));

        $path = $this->tempPath();

        match ($format) {
            'jpeg', 'jpg' => imagejpeg($image, $path, 90),
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path, 90),
            'gif' => imagegif($image, $path),
        };

        imagedestroy($image);

        $extension = $format === 'jpeg' ? 'jpg' : $format;

        return new UploadedFile($path, $name ?? "photo.{$extension}", null, null, true);
    }

    /** A file with arbitrary bytes and a chosen client filename. */
    protected function rawFile(string $contents, string $name): UploadedFile
    {
        $path = $this->tempPath();
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function pdfFile(string $name = 'brochure.pdf'): UploadedFile
    {
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        return $this->rawFile($pdf, $name);
    }

    /**
     * Upload a file and return the decoded "data" payload.
     *
     * @return array<string, mixed>
     */
    protected function upload(UploadedFile $file, array $fields = [], string $tenant = self::TENANT): array
    {
        $response = $this->post('/api/v1/media', array_merge(['module' => 'products', 'file' => $file], $fields), $this->tenantHeaders($tenant));

        $response->assertCreated();

        return $response->json('data');
    }

    /** Directory of a stored media item inside the faked disk. */
    protected function mediaDirectory(array $data): string
    {
        $branch = $data['type'] === 'image' ? 'images' : 'files';
        $created = new \DateTimeImmutable($data['created_at']);

        return implode('/', [$this->mediaRoot(), 'tenants', $data['tenant'], $branch, $data['module'], $created->format('Y'), $created->format('m'), $data['id']]);
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'media-api-test-');
        $this->tempFiles[] = $path;

        return $path;
    }
}
