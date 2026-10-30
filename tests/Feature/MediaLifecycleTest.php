<?php

namespace Tests\Feature;

use Tests\TestCase;

class MediaLifecycleTest extends TestCase
{
    public function test_delete_removes_files_and_the_media_directory_only(): void
    {
        $data = $this->upload($this->imageFile());
        $dir = $this->mediaDirectory($data);

        $this->assertDirectoryExists($dir);

        $this->deleteJson('/api/v1/media/'.$data['id'], [], $this->tenantHeaders())
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['id' => $data['id'], 'deleted' => true]]);

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertDirectoryExists(dirname($dir), 'the month directory must survive');
        $this->assertDirectoryExists($this->mediaRoot().'/tenants/lzhub');

        $this->getJson('/api/v1/media/'.$data['id'])->assertNotFound();
        $this->deleteJson('/api/v1/media/'.$data['id'], [], $this->tenantHeaders())->assertNotFound();
    }

    public function test_delete_requires_the_tenant_header(): void
    {
        $data = $this->upload($this->imageFile());

        $this->deleteJson('/api/v1/media/'.$data['id'])->assertStatus(400);
        $this->assertFileExists($this->mediaDirectory($data).'/image.webp');
    }

    public function test_replace_keeps_the_id_and_swaps_the_content(): void
    {
        $data = $this->upload($this->imageFile('jpeg', 800, 600, 'before.jpg'), ['entity_id' => 'PRD001']);
        $dir = $this->mediaDirectory($data);
        $before = file_get_contents("$dir/image.webp");

        $response = $this->put('/api/v1/media/'.$data['id'], ['file' => $this->imageFile('png', 300, 200, 'after.png')], $this->tenantHeaders());

        $response->assertOk()
            ->assertJsonPath('data.id', $data['id'])
            ->assertJsonPath('data.entity_id', 'PRD001')
            ->assertJsonPath('data.module', 'products')
            ->assertJsonPath('data.original_filename', 'after.png')
            ->assertJsonPath('data.original_mime_type', 'image/png')
            ->assertJsonPath('data.width', 300)
            ->assertJsonPath('data.height', 200)
            ->assertJsonPath('data.created_at', $data['created_at']);

        $this->assertDirectoryExists($dir);
        $this->assertNotSame($before, file_get_contents("$dir/image.webp"));
        $this->assertSame([300, 200], array_slice(getimagesize("$dir/image.webp"), 0, 2));
        $this->assertSame('after.png', json_decode(file_get_contents("$dir/metadata.json"), true)['original_filename']);
        $this->assertSame([], glob("$dir.old-*"));
        $this->assertSame([], glob("$dir.staging-*"));
        $this->assertCount(1, glob(dirname($dir).'/*'), 'only the media directory remains in the month folder');

        // POST with _method=PUT is accepted for browsers that cannot send multipart PUT.
        $this->post('/api/v1/media/'.$data['id'], ['_method' => 'PUT', 'file' => $this->imageFile('jpeg', 120, 80)], $this->tenantHeaders())
            ->assertOk()->assertJsonPath('data.width', 120);

        $this->post('/api/v1/media/'.$data['id'], ['file' => $this->imageFile('jpeg', 90, 60)], $this->tenantHeaders())
            ->assertOk()->assertJsonPath('data.width', 90);
    }

    public function test_replace_can_switch_from_image_to_document_and_update_visibility(): void
    {
        $data = $this->upload($this->imageFile());
        $imageDir = $this->mediaDirectory($data);

        $response = $this->put('/api/v1/media/'.$data['id'], ['file' => $this->pdfFile('spec.pdf'), 'visibility' => 'private'], $this->tenantHeaders());

        $response->assertOk()
            ->assertJsonPath('data.id', $data['id'])
            ->assertJsonPath('data.type', 'file')
            ->assertJsonPath('data.format', 'pdf')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.thumbnail_url', null);

        $fileDir = $this->mediaDirectory($response->json('data'));

        $this->assertStringContainsString('/files/products/', $fileDir);
        $this->assertFileExists("$fileDir/file.pdf");
        $this->assertDirectoryDoesNotExist($imageDir);
        $this->assertSame([], glob("$imageDir.old-*"));

        $this->getJson('/api/v1/media/'.$data['id'])->assertForbidden();
        $this->get('/api/v1/media/'.$data['id'], $this->tenantHeaders())->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_replace_with_an_invalid_file_keeps_the_existing_media(): void
    {
        $data = $this->upload($this->imageFile('jpeg', 800, 600));
        $dir = $this->mediaDirectory($data);
        $before = file_get_contents("$dir/image.webp");

        $this->put('/api/v1/media/'.$data['id'], ['file' => $this->rawFile('<?php', 'x.php')], $this->tenantHeaders())->assertStatus(422);
        $this->put('/api/v1/media/'.$data['id'], [], $this->tenantHeaders())->assertStatus(422);

        $this->assertSame($before, file_get_contents("$dir/image.webp"));
        $this->assertSame([], glob("$dir.staging-*"));
        $this->getJson('/api/v1/media/'.$data['id'].'/metadata')->assertOk()->assertJsonPath('data.width', 800);
    }

    public function test_assign_links_existing_media_to_an_entity_without_touching_files(): void
    {
        $data = $this->upload($this->imageFile('jpeg', 640, 480));
        $this->assertNull($data['entity_id']);

        $dir = $this->mediaDirectory($data);
        $image = file_get_contents("$dir/image.webp");
        $thumb = file_get_contents("$dir/thumb.webp");

        $response = $this->patchJson('/api/v1/media/'.$data['id'], ['entity_id' => 'PRD101'], $this->tenantHeaders());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $data['id'])
            ->assertJsonPath('data.entity_id', 'PRD101')
            ->assertJsonPath('data.module', 'products')
            ->assertJsonPath('data.width', 640)
            ->assertJsonPath('data.created_at', $data['created_at']);

        $this->assertSame($image, file_get_contents("$dir/image.webp"));
        $this->assertSame($thumb, file_get_contents("$dir/thumb.webp"));
        $this->assertSame('PRD101', json_decode(file_get_contents("$dir/metadata.json"), true)['entity_id']);
        $this->assertSame([], glob("$dir.*"));

        $this->getJson('/api/v1/media?module=products&entity_id=PRD101', $this->tenantHeaders())
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $data['id']);

        // Form-encoded bodies and POST + _method=PATCH work for browsers too.
        $this->patch('/api/v1/media/'.$data['id'], ['entity_id' => 'PRD102'], $this->tenantHeaders())
            ->assertOk()->assertJsonPath('data.entity_id', 'PRD102');
        $this->post('/api/v1/media/'.$data['id'], ['_method' => 'PATCH', 'entity_id' => 'PRD103'], $this->tenantHeaders())
            ->assertOk()->assertJsonPath('data.entity_id', 'PRD103');

        // An empty value clears the link.
        $this->patchJson('/api/v1/media/'.$data['id'], ['entity_id' => ''], $this->tenantHeaders())
            ->assertOk()->assertJsonPath('data.entity_id', null);
        $this->getJson('/api/v1/media?entity_id=PRD103', $this->tenantHeaders())->assertJsonPath('meta.total', 0);
    }

    public function test_assign_validates_input_and_is_tenant_scoped(): void
    {
        $data = $this->upload($this->imageFile(), ['entity_id' => 'PRD001']);
        $url = '/api/v1/media/'.$data['id'];

        $this->patchJson($url, [], $this->tenantHeaders())->assertStatus(422)->assertJsonPath('success', false);
        $this->patchJson($url, ['entity_id' => '../etc'], $this->tenantHeaders())->assertStatus(422);
        $this->patchJson($url, ['entity_id' => str_repeat('a', 101)], $this->tenantHeaders())->assertStatus(422);
        $this->patchJson($url, ['entity_id' => 'PRD002'])->assertStatus(400);
        $this->patchJson($url, ['entity_id' => 'PRD002'], $this->tenantHeaders('otherco'))->assertNotFound();
        $this->patchJson('/api/v1/media/'.str_repeat('0', 26), ['entity_id' => 'PRD002'], $this->tenantHeaders())->assertNotFound();

        $this->getJson($url.'/metadata', $this->tenantHeaders())->assertJsonPath('data.entity_id', 'PRD001');
    }

    public function test_listing_filters_and_paginates(): void
    {
        $a = $this->upload($this->imageFile('jpeg', 100, 100), ['module' => 'products', 'entity_id' => 'PRD001']);
        $b = $this->upload($this->imageFile('png', 100, 100), ['module' => 'products', 'entity_id' => 'PRD002']);
        $c = $this->upload($this->pdfFile(), ['module' => 'documents', 'entity_id' => 'PRD001']);
        $this->upload($this->imageFile('jpeg', 100, 100), ['module' => 'gallery'], 'otherco');

        $all = $this->getJson('/api/v1/media', $this->tenantHeaders())->assertOk();
        $all->assertJsonPath('meta.total', 3)->assertJsonPath('meta.page', 1)->assertJsonPath('meta.total_pages', 1);
        $this->assertEqualsCanonicalizing([$a['id'], $b['id'], $c['id']], array_column($all->json('data'), 'id'));

        $this->getJson('/api/v1/media?module=products', $this->tenantHeaders())->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/media?module=products&entity_id=PRD001', $this->tenantHeaders())
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a['id']);
        $this->getJson('/api/v1/media?entity_id=PRD001', $this->tenantHeaders())->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/media?media_type=file', $this->tenantHeaders())->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $c['id']);
        $this->getJson('/api/v1/media?media_type=image', $this->tenantHeaders())->assertJsonPath('meta.total', 2);

        $year = (int) date('Y');
        $month = (int) date('m');
        $this->getJson("/api/v1/media?year=$year&month=$month", $this->tenantHeaders())->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/media?year=2001&month=1', $this->tenantHeaders())->assertJsonPath('meta.total', 0);

        $page = $this->getJson('/api/v1/media?per_page=2&page=2', $this->tenantHeaders())->assertOk();
        $page->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total_pages', 2);
        $this->assertCount(1, $page->json('data'));

        $this->getJson('/api/v1/media?module=../x', $this->tenantHeaders())->assertStatus(422);
        $this->getJson('/api/v1/media?media_type=video', $this->tenantHeaders())->assertStatus(422);
        $this->getJson('/api/v1/media')->assertStatus(400);
    }
}
