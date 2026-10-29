<?php

namespace Tests\Unit;

use App\Support\DeploymentInfo;
use PHPUnit\Framework\TestCase;

class DeploymentInfoTest extends TestCase
{
    /** @var array<int, string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_missing_file_gives_no_deployment(): void
    {
        $this->assertNull((new DeploymentInfo('/nonexistent/deployment.json'))->summary());
    }

    public function test_commit_is_shortened_to_eight_characters(): void
    {
        $info = new DeploymentInfo($this->write(['commit' => 'abcdef1234567890abcdef1234567890abcdef12', 'deployed_at' => '2026-10-29T08:00:00Z']));

        $this->assertSame(['commit' => 'abcdef12', 'deployed_at' => '2026-10-29T08:00:00Z'], $info->summary());
    }

    public function test_invalid_contents_are_ignored(): void
    {
        $this->assertNull((new DeploymentInfo($this->write('not json')))->summary());
        $this->assertNull((new DeploymentInfo($this->write(['commit' => '../etc/passwd'])))->summary());
        $this->assertNull((new DeploymentInfo($this->write(['deployed_at' => 'x'])))->summary());
    }

    private function write(mixed $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'deployment-');
        $this->files[] = $path;
        file_put_contents($path, is_string($contents) ? $contents : json_encode($contents));

        return $path;
    }
}
