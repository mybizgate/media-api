<?php

namespace Tests\Feature;

use App\Support\DeploymentInfo;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_reports_storage_gd_and_webp(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'service' => 'MyBizGate Media API',
            'version' => '1.0.0',
            'storage' => true,
            'gd' => true,
            'webp' => true,
        ]);

        $this->assertDirectoryExists($this->mediaRoot().'/tenants');
        $this->assertStringNotContainsString($this->mediaRoot(), $response->getContent());

        // No bootstrap/deployment.json in a local checkout: the key is present but null.
        $this->assertArrayHasKey('deployment', $response->json());
    }

    public function test_health_reports_the_deployed_commit_when_a_deployment_file_exists(): void
    {
        $this->app->instance(DeploymentInfo::class, new DeploymentInfo($this->deploymentFile([
            'commit' => '0123456789abcdef0123456789abcdef01234567',
            'deployed_at' => '2026-10-29T08:00:00Z',
            'ref' => 'refs/heads/deploy',
        ])));

        $this->getJson('/api/v1/health')->assertOk()->assertJson([
            'deployment' => ['commit' => '01234567', 'deployed_at' => '2026-10-29T08:00:00Z'],
        ])->assertJsonMissingPath('deployment.ref');
    }

    private function deploymentFile(array $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'deployment-');
        file_put_contents($path, json_encode($contents));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    public function test_root_route_points_to_the_api(): void
    {
        $this->getJson('/')->assertOk()->assertJsonPath('service', 'MyBizGate Media API');
    }

    public function test_ping_route_responds(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pong', true);
    }
}
