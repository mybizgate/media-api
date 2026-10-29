<?php

namespace App\Support;

use Throwable;

/**
 * Non-secret identity of the running deployment.
 *
 * GitHub Actions writes bootstrap/deployment.json into the release package
 * (see .github/workflows/deploy.yml). The file is read directly, not through
 * config(), so it keeps working even when the server has a cached config.
 * It is absent in local checkouts and in tests, where the health endpoint
 * reports "deployment": null.
 */
class DeploymentInfo
{
    public const FILENAME = 'deployment.json';

    public function __construct(private readonly ?string $path = null)
    {
    }

    public function path(): string
    {
        return $this->path ?? base_path('bootstrap/'.self::FILENAME);
    }

    /**
     * @return array{commit: string, deployed_at: string|null}|null
     */
    public function summary(): ?array
    {
        $raw = $this->read();

        if ($raw === null) {
            return null;
        }

        $commit = $raw['commit'] ?? null;

        if (! is_string($commit) || ! preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
            return null;
        }

        $deployedAt = $raw['deployed_at'] ?? null;

        return [
            'commit' => substr($commit, 0, 8),
            'deployed_at' => is_string($deployedAt) ? $deployedAt : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        $path = $this->path();

        try {
            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $decoded = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
