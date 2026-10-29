<?php

namespace Tests\Feature;

use Tests\TestCase;

class MediaAccessTest extends TestCase
{
    public function test_public_image_can_be_viewed_without_a_tenant_header(): void
    {
        $data = $this->upload($this->imageFile('jpeg', 640, 480));

        $response = $this->get('/api/v1/media/'.$data['id']);

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'max-age=86400, public')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(file_get_contents($this->mediaDirectory($data).'/image.webp'), $response->getFile()->getContent());
    }

    public function test_thumbnail_is_served(): void
    {
        $data = $this->upload($this->imageFile('png', 1000, 500));

        $response = $this->get('/api/v1/media/'.$data['id'].'/thumbnail');

        $response->assertOk()->assertHeader('Content-Type', 'image/webp');

        $size = getimagesizefromstring($response->getFile()->getContent());
        $this->assertSame([400, 200], [$size[0], $size[1]]);
    }

    public function test_document_has_no_thumbnail(): void
    {
        $data = $this->upload($this->pdfFile(), ['module' => 'documents']);

        $this->getJson('/api/v1/media/'.$data['id'].'/thumbnail')->assertNotFound();
    }

    public function test_metadata_endpoint_returns_the_resource(): void
    {
        $data = $this->upload($this->imageFile('jpeg', 640, 480, 'photo.jpg'), ['entity_id' => 'PRD001']);

        $this->getJson('/api/v1/media/'.$data['id'].'/metadata')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $data['id'])
            ->assertJsonPath('data.entity_id', 'PRD001')
            ->assertJsonPath('data.original_filename', 'photo.jpg')
            ->assertJsonPath('data.width', 640)
            ->assertJsonMissingPath('data.path');
    }

    public function test_download_uses_the_original_filename_with_the_stored_extension(): void
    {
        $data = $this->upload($this->imageFile('jpeg', 100, 100, 'My Photo.JPG'));

        $response = $this->get('/api/v1/media/'.$data['id'].'/download');

        $response->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('My Photo.webp', $response->headers->get('Content-Disposition'));

        $doc = $this->upload($this->pdfFile('Price List.pdf'), ['module' => 'documents']);

        $response = $this->get('/api/v1/media/'.$doc['id'].'/download');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Price List.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_previews_inline_and_other_documents_download(): void
    {
        $pdf = $this->upload($this->pdfFile(), ['module' => 'documents']);
        $response = $this->get('/api/v1/media/'.$pdf['id']);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $txt = $this->upload($this->rawFile("hello\n", 'notes.txt'), ['module' => 'documents']);
        $response = $this->get('/api/v1/media/'.$txt['id']);
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_private_media_requires_the_owning_tenant_header(): void
    {
        $data = $this->upload($this->imageFile(), ['visibility' => 'private']);

        $this->assertSame('private', $data['visibility']);

        $this->getJson('/api/v1/media/'.$data['id'])->assertForbidden();
        $this->getJson('/api/v1/media/'.$data['id'].'/thumbnail')->assertForbidden();
        $this->getJson('/api/v1/media/'.$data['id'].'/download')->assertForbidden();
        $this->getJson('/api/v1/media/'.$data['id'].'/metadata')->assertForbidden();

        $this->get('/api/v1/media/'.$data['id'], $this->tenantHeaders('otherco'))->assertNotFound();

        $this->get('/api/v1/media/'.$data['id'], $this->tenantHeaders())
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_tenant_isolation_for_reads_writes_and_listing(): void
    {
        $data = $this->upload($this->imageFile());

        $this->get('/api/v1/media/'.$data['id'], $this->tenantHeaders('otherco'))->assertNotFound();
        $this->getJson('/api/v1/media/'.$data['id'].'/metadata', $this->tenantHeaders('otherco'))->assertNotFound();
        $this->deleteJson('/api/v1/media/'.$data['id'], [], $this->tenantHeaders('otherco'))->assertNotFound();
        $this->put('/api/v1/media/'.$data['id'], ['file' => $this->imageFile()], $this->tenantHeaders('otherco'))->assertNotFound();

        $this->getJson('/api/v1/media', $this->tenantHeaders('otherco'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/media', $this->tenantHeaders())->assertOk()->assertJsonPath('meta.total', 1);

        $this->assertFileExists($this->mediaDirectory($data).'/image.webp');
        $this->assertDirectoryDoesNotExist($this->mediaRoot().'/tenants/otherco');
    }

    public function test_unknown_media_is_not_found(): void
    {
        $this->getJson('/api/v1/media/01hzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound()->assertJsonPath('success', false);
        $this->getJson('/api/v1/media/not-a-valid-id')->assertNotFound();
    }

    public function test_cors_preflight_allows_the_tenant_origin_and_header(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/media', [], [], [], [
            'HTTP_ORIGIN' => 'https://lzhub.mybizgate.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-client-code, content-type',
        ]);

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://lzhub.mybizgate.com');
        $this->assertStringContainsStringIgnoringCase('x-client-code', $response->headers->get('Access-Control-Allow-Headers'));

        $this->call('OPTIONS', '/api/v1/media', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->call('OPTIONS', '/api/v1/media', [], [], [], [
            'HTTP_ORIGIN' => 'https://catparadise.mybizgate.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://catparadise.mybizgate.com');
    }
}
