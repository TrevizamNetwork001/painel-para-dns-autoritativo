<?php

namespace App\Support;

class AgentArtifact
{
    public static function path(): string
    {
        return base_path('agent/dns-center-agent.py');
    }

    public static function availableVersion(): ?string
    {
        $path = self::path();

        if (! is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        $version = null;
        $linesRead = 0;

        while ($linesRead < 60 && ($line = fgets($handle)) !== false) {
            $linesRead++;

            if (preg_match('/^AGENT_VERSION\s*=\s*"(\d+\.\d+\.\d+)"/', $line, $matches) === 1) {
                $version = $matches[1];
                break;
            }
        }

        fclose($handle);

        return $version;
    }

    public static function sha256(): ?string
    {
        $path = self::path();

        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    public static function isNewerThan(?string $installedVersion, ?string $availableVersion): bool
    {
        if ($installedVersion === null || $availableVersion === null) {
            return false;
        }

        return version_compare($availableVersion, $installedVersion, '>');
    }
}
