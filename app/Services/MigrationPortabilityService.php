<?php

namespace App\Services;

class MigrationPortabilityService
{
    public function verify(): array
    {
        $files = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                continue;
            }

            $files[$path] = $contents;
        }

        return $this->verifyContents($files);
    }

    public function verifyContents(array $files): array
    {
        $issues = [];

        foreach ($files as $path => $contents) {
            if (! is_string($contents) || $contents === '') {
                continue;
            }

            if ($this->containsStoredGeneratedBigIntGuard($contents)) {
                $issues[] = [
                    'path' => $path,
                    'severity' => 'fail',
                    'summary' => 'Stored generated BIGINT guard columns are not allowed. Use VIRTUAL guard columns instead because MySQL 8.4 rejects stored generated columns built from FK base columns with cascade or set-null actions.',
                ];
            }
        }

        return [
            'status' => empty($issues) ? 'pass' : 'fail',
            'issues' => $issues,
        ];
    }

    private function containsStoredGeneratedBigIntGuard(string $contents): bool
    {
        return (bool) preg_match(
            '/ADD\s+COLUMN\s+\w+_guard\s+BIGINT(?:\s+UNSIGNED)?\s+GENERATED\s+ALWAYS\s+AS\s*\(.*?\)\s+STORED/is',
            $contents
        );
    }
}
