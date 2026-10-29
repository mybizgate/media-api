<?php

namespace Tests\Unit;

use App\DTO\Media\MediaData;
use App\Exceptions\MediaException;
use App\Services\Media\MediaPathService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MediaPathServiceTest extends TestCase
{
    private function media(string $type = 'image', string $module = 'products'): MediaData
    {
        $at = CarbonImmutable::parse('2026-10-29T10:00:00+00:00');

        return new MediaData('01k8rj2w9h3x5n7q9s1t3v5x7z', 'lzhub', $module, 'PRD001', $type, 'public', 'a.png', $type === 'image' ? 'image.webp' : 'file.pdf', 'image/webp', 'image/png', 1, 2, 10, 10, true, $at, $at);
    }

    public function test_layout_follows_tenant_branch_module_year_month_id(): void
    {
        $paths = app(MediaPathService::class);

        $this->assertSame('tenants/lzhub/images/products/2026/10/01k8rj2w9h3x5n7q9s1t3v5x7z', $paths->mediaDirectory($this->media()));
        $this->assertSame('tenants/lzhub/files/documents/2026/10/01k8rj2w9h3x5n7q9s1t3v5x7z', $paths->mediaDirectory($this->media('file', 'documents')));
        $this->assertStringStartsWith($this->mediaRoot().'/', $paths->absoluteMediaDirectory($this->media()));
    }

    public function test_segments_are_validated(): void
    {
        $paths = app(MediaPathService::class);

        $this->assertSame('carspa-001', $paths->assertTenant('carspa-001'));
        $this->assertSame('product_images', $paths->assertModule('product_images'));
        $this->assertNull($paths->assertEntityId(''));

        foreach (['..', 'a/b', 'a\\b', 'A', 'x'] as $bad) {
            try {
                $paths->assertTenant($bad);
                $this->fail("tenant '$bad' should be rejected");
            } catch (MediaException $e) {
                $this->assertSame(400, $e->status());
            }
        }

        foreach (['../x', 'Products', 'a b', str_repeat('a', 51)] as $bad) {
            try {
                $paths->assertModule($bad);
                $this->fail("module '$bad' should be rejected");
            } catch (MediaException $e) {
                $this->assertSame(422, $e->status());
            }
        }

        $this->expectException(MediaException::class);
        $paths->assertMediaId('../x');
    }

    public function test_is_inside_tenant_and_redaction(): void
    {
        $paths = app(MediaPathService::class);
        $inside = $paths->absolute('tenants/lzhub/images');
        mkdir($inside, 0755, true);
        mkdir($paths->absolute('tenants/other'), 0755, true);

        $this->assertTrue($paths->isInsideTenant($inside, 'lzhub'));
        $this->assertFalse($paths->isInsideTenant($paths->absolute('tenants/other'), 'lzhub'));
        $this->assertFalse($paths->isInsideTenant($paths->absolute('tenants/lzhub/../other'), 'lzhub'));
        $this->assertFalse($paths->isInsideTenant('/etc', 'lzhub'));

        $this->assertSame('failed at [media-root]/tenants/lzhub', $paths->redact('failed at '.$this->mediaRoot().'/tenants/lzhub'));
    }
}
