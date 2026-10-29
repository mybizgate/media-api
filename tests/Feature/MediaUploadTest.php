<?php

namespace Tests\Feature;

use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    public function test_upload_jpeg_is_converted_to_webp_with_thumbnail_and_metadata(): void
    {
        $data = $this->upload($this->imageFile('jpeg', 800, 600, 'My Photo.JPG'), ['entity_id' => 'PRD001']);

        $this->assertSame('image', $data['type']);
        $this->assertSame('webp', $data['format']);
        $this->assertSame('image/webp', $data['mime_type']);
        $this->assertSame('image/jpeg', $data['original_mime_type']);
        $this->assertSame('My Photo.JPG', $data['original_filename']);
        $this->assertSame('products', $data['module']);
        $this->assertSame('PRD001', $data['entity_id']);
        $this->assertSame('public', $data['visibility']);
        $this->assertSame(800, $data['width']);
        $this->assertSame(600, $data['height']);
        $this->assertTrue($data['thumbnail']);
        $this->assertGreaterThan(0, $data['size_bytes']);
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $data['id']);

        $dir = $this->mediaDirectory($data);
        $this->assertFileExists("$dir/image.webp");
        $this->assertFileExists("$dir/thumb.webp");
        $this->assertFileExists("$dir/metadata.json");
        $this->assertCount(3, glob("$dir/*"));

        $this->assertSame('image/webp', getimagesize("$dir/image.webp")['mime']);
        $this->assertSame($data['size_bytes'], filesize("$dir/image.webp"));

        $metadata = json_decode(file_get_contents("$dir/metadata.json"), true);
        $this->assertSame($data['id'], $metadata['id']);
        $this->assertSame('lzhub', $metadata['tenant']);
        $this->assertSame('image', $metadata['media_type']);
        $this->assertSame('image.webp', $metadata['stored_filename']);
        $this->assertSame('My Photo.JPG', $metadata['original_filename']);
        $this->assertSame(800, $metadata['width']);
        $this->assertTrue($metadata['thumbnail']);
        $this->assertArrayHasKey('created_at', $metadata);
        $this->assertArrayHasKey('updated_at', $metadata);

        // Only API URLs are exposed, never a filesystem path.
        $this->assertStringStartsWith('http://localhost/api/v1/media/'.$data['id'], $data['view_url']);
        $this->assertSame($data['view_url'].'/thumbnail', $data['thumbnail_url']);
        $this->assertSame($data['view_url'].'/download', $data['download_url']);
        $this->assertStringNotContainsString($this->mediaRoot(), json_encode($data));
    }

    public function test_upload_png_and_webp_are_accepted(): void
    {
        $png = $this->upload($this->imageFile('png', 640, 480));
        $this->assertSame('image/png', $png['original_mime_type']);
        $this->assertSame('webp', $png['format']);

        $webp = $this->upload($this->imageFile('webp', 640, 480));
        $this->assertSame('image/webp', $webp['original_mime_type']);
        $this->assertSame('webp', $webp['format']);
    }

    public function test_large_image_is_resized_within_bounds_and_small_image_is_not_upscaled(): void
    {
        $large = $this->upload($this->imageFile('jpeg', 2400, 1800));
        $this->assertSame(1600, $large['width']);
        $this->assertSame(1200, $large['height']);
        [$w, $h] = getimagesize($this->mediaDirectory($large).'/image.webp');
        $this->assertSame([1600, 1200], [$w, $h]);

        $small = $this->upload($this->imageFile('png', 300, 200));
        $this->assertSame(300, $small['width']);
        $this->assertSame(200, $small['height']);
    }

    public function test_thumbnail_fits_configured_bounds(): void
    {
        $data = $this->upload($this->imageFile('jpeg', 1200, 900));

        [$w, $h] = getimagesize($this->mediaDirectory($data).'/thumb.webp');

        $this->assertSame([400, 300], [$w, $h]);
    }

    public function test_upload_response_uses_the_documented_envelope(): void
    {
        $response = $this->post('/api/v1/media', ['module' => 'products', 'file' => $this->imageFile()], $this->tenantHeaders());

        $response->assertCreated()->assertJsonStructure([
            'success',
            'data' => ['id', 'tenant', 'module', 'entity_id', 'type', 'format', 'size_bytes', 'original_size_bytes', 'view_url', 'thumbnail_url', 'download_url'],
        ])->assertJsonPath('success', true);
    }

    public function test_pdf_document_is_stored_in_the_files_branch(): void
    {
        $data = $this->upload($this->pdfFile('Price List 2026.pdf'), ['module' => 'documents']);

        $this->assertSame('file', $data['type']);
        $this->assertSame('pdf', $data['format']);
        $this->assertSame('application/pdf', $data['mime_type']);
        $this->assertNull($data['thumbnail_url']);
        $this->assertFalse($data['thumbnail']);

        $dir = $this->mediaDirectory($data);
        $this->assertStringContainsString('/files/documents/', $dir);
        $this->assertFileExists("$dir/file.pdf");
        $this->assertFileExists("$dir/metadata.json");
        $this->assertFileDoesNotExist("$dir/thumb.webp");
    }

    public function test_txt_and_csv_documents_are_accepted(): void
    {
        $txt = $this->upload($this->rawFile("hello world\n", 'notes.txt'), ['module' => 'documents']);
        $this->assertSame('txt', $txt['format']);

        $csv = $this->upload($this->rawFile("sku,qty\nA1,2\n", 'stock.csv'), ['module' => 'documents']);
        $this->assertSame('csv', $csv['format']);
    }

    public function test_missing_tenant_header_is_rejected(): void
    {
        $this->post('/api/v1/media', ['module' => 'products', 'file' => $this->imageFile()], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_invalid_tenant_codes_are_rejected(): void
    {
        foreach (['../etc', 'LZHUB', 'a', 'lz hub', 'lz/hub', 'lz\\hub', str_repeat('a', 51)] as $tenant) {
            $this->post('/api/v1/media', ['module' => 'products', 'file' => $this->imageFile()], $this->tenantHeaders($tenant))
                ->assertStatus(400);
        }

        $this->assertDirectoryDoesNotExist($this->mediaRoot().'/tenants/../etc');
    }

    public function test_tenant_in_form_data_is_ignored(): void
    {
        $data = $this->upload($this->imageFile(), ['tenant' => 'other', 'client' => 'other']);

        $this->assertSame('lzhub', $data['tenant']);
    }

    public function test_invalid_module_and_entity_id_are_rejected(): void
    {
        $this->post('/api/v1/media', ['module' => '../x', 'file' => $this->imageFile()], $this->tenantHeaders())
            ->assertStatus(422)->assertJsonValidationErrors(['module']);

        $this->post('/api/v1/media', ['module' => 'Products', 'file' => $this->imageFile()], $this->tenantHeaders())
            ->assertStatus(422);

        $this->post('/api/v1/media', ['file' => $this->imageFile()], $this->tenantHeaders())
            ->assertStatus(422)->assertJsonValidationErrors(['module']);

        $this->post('/api/v1/media', ['module' => 'products', 'entity_id' => '../x', 'file' => $this->imageFile()], $this->tenantHeaders())
            ->assertStatus(422)->assertJsonValidationErrors(['entity_id']);

        $this->post('/api/v1/media', ['module' => 'products', 'visibility' => 'secret', 'file' => $this->imageFile()], $this->tenantHeaders())
            ->assertStatus(422)->assertJsonValidationErrors(['visibility']);
    }

    public function test_unsupported_and_dangerous_files_are_rejected(): void
    {
        $cases = [
            'gif image' => $this->imageFile('gif', 50, 50),
            'php script' => $this->rawFile("<?php echo 'x';", 'shell.php'),
            'php disguised as png' => $this->rawFile("<?php echo 'x';", 'shell.png'),
            'html' => $this->rawFile('<html><script>alert(1)</script></html>', 'page.html'),
            'javascript' => $this->rawFile('alert(1)', 'app.js'),
            'shell script' => $this->rawFile("#!/bin/sh\nrm -rf /", 'run.sh'),
            'windows exe' => $this->rawFile("MZ\x90\x00".str_repeat("\x00", 64), 'setup.exe'),
            'jar' => $this->rawFile("PK\x03\x04".str_repeat("\x00", 64), 'app.jar'),
            'zip' => $this->rawFile("PK\x03\x04".str_repeat("\x00", 64), 'archive.zip'),
            'svg' => $this->rawFile('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg'),
            'no extension' => $this->rawFile('plain text', 'README'),
            'extension mismatch' => $this->imageFile('png', 50, 50, 'photo.jpg'),
        ];

        foreach ($cases as $label => $file) {
            $this->post('/api/v1/media', ['module' => 'products', 'file' => $file], $this->tenantHeaders())
                ->assertStatus(422, $label)
                ->assertJsonPath('success', false);
        }

        $this->assertSame([], glob($this->mediaRoot().'/tenants/lzhub/*/*/*/*/*'), 'nothing may be stored for rejected uploads');
    }

    public function test_oversized_uploads_are_rejected(): void
    {
        config(['media.image.max_size_kb' => 1]);

        $this->post('/api/v1/media', ['module' => 'products', 'file' => $this->imageFile('png', 400, 400)], $this->tenantHeaders())
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'The image may not be larger than 1 KB.');

        config(['media.file.max_size_kb' => 1]);

        $this->post('/api/v1/media', ['module' => 'documents', 'file' => $this->rawFile(str_repeat('a', 2048), 'big.txt')], $this->tenantHeaders())
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'The file may not be larger than 1 KB.');
    }

    public function test_path_traversal_in_filename_never_leaves_the_media_directory(): void
    {
        $data = $this->upload($this->imageFile('png', 50, 50, '../../../evil.png'));

        $this->assertSame('evil.png', $data['original_filename']);
        $this->assertFileExists($this->mediaDirectory($data).'/image.webp');
        $this->assertFileDoesNotExist($this->mediaRoot().'/evil.png');
        $this->assertFileDoesNotExist(dirname($this->mediaRoot(), 3).'/evil.png');
    }

    public function test_path_traversal_in_media_id_is_not_found(): void
    {
        $this->getJson('/api/v1/media/..%2F..%2Fetc%2Fpasswd')->assertNotFound();
        $this->getJson('/api/v1/media/../../etc/passwd/metadata')->assertNotFound();
        $this->deleteJson('/api/v1/media/..%2Fother', [], $this->tenantHeaders())->assertNotFound();
    }

    public function test_failed_upload_leaves_no_staging_directory(): void
    {
        $this->post('/api/v1/media', ['module' => 'products', 'file' => $this->rawFile('nope', 'x.exe')], $this->tenantHeaders())->assertStatus(422);

        $this->assertSame([], glob($this->mediaRoot().'/tenants/*/*/*/*/*/*.staging-*'));
    }
}
