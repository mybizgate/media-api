<?php

namespace Tests\Unit;

use App\Services\Media\ImageProcessingService;
use Tests\TestCase;

class ImageProcessingServiceTest extends TestCase
{
    public function test_webp_is_supported_by_gd(): void
    {
        $service = new ImageProcessingService;

        $this->assertTrue($service->available());
        $this->assertTrue($service->supportsWebp());
    }

    public function test_to_webp_fits_without_upscaling(): void
    {
        $service = new ImageProcessingService;

        $large = $service->decode($this->imageFile('jpeg', 2400, 1800)->getRealPath(), 'image/jpeg');
        $result = $service->toWebp($large, 1600, 1600, 80);
        $service->free($large);

        $this->assertSame([1600, 1200], [$result->width, $result->height]);
        $this->assertSame('image/webp', getimagesizefromstring($result->binary)['mime']);
        $this->assertSame(strlen($result->binary), $result->size());

        $small = $service->decode($this->imageFile('png', 300, 200)->getRealPath(), 'image/png');
        $result = $service->toWebp($small, 1600, 1600, 80);
        $service->free($small);

        $this->assertSame([300, 200], [$result->width, $result->height]);
    }

    public function test_png_transparency_is_preserved(): void
    {
        $image = imagecreatetruecolor(20, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $path = tempnam(sys_get_temp_dir(), 'media-api-test-');
        imagepng($image, $path);
        imagedestroy($image);

        $service = new ImageProcessingService;
        $decoded = $service->decode($path, 'image/png');
        $webp = imagecreatefromstring($service->toWebp($decoded, 100, 100, 80)->binary);
        $service->free($decoded);
        @unlink($path);

        $alpha = (imagecolorat($webp, 10, 10) >> 24) & 0x7F;
        $this->assertSame(127, $alpha, 'fully transparent pixel must stay transparent');
    }
}
