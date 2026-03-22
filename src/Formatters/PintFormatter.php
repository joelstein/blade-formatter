<?php

namespace JoelStein\BladeFormatter\Formatters;

use RuntimeException;
use Symfony\Component\Process\Process;

class PintFormatter
{
    public function format(string $phpCode, ?string $configPath = null): string
    {
        $tmpDir = sys_get_temp_dir().'/blade-fmt-'.uniqid();
        mkdir($tmpDir);
        $tmpFile = $tmpDir.'/component.php';

        try {
            file_put_contents($tmpFile, $phpCode);

            $this->runPint($tmpFile, $configPath);

            $formatted = rtrim((string) file_get_contents($tmpFile));

            // Pint strips the closing PHP tag (PSR-12), but SFCs need it
            if (! str_ends_with($formatted, '?'.'>')) {
                $formatted .= "\n".'?'.'>';
            }

            return $formatted;
        } finally {
            @unlink($tmpFile);
            @rmdir($tmpDir);
        }
    }

    private function runPint(string $filePath, ?string $configPath): void
    {
        $pintPath = $this->resolvePintPath();

        $command = [$pintPath, $filePath, '--quiet'];

        if ($configPath !== null) {
            $command[] = '--config';
            $command[] = $configPath;
        }

        $process = new Process($command);
        $process->setTimeout(15);
        $process->run();

        // Pint exits with 1 when it makes changes, which is fine
        if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
            throw new RuntimeException('Pint failed: '.($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    private function resolvePintPath(): string
    {
        // Check for Pint in the project vendor directory
        try {
            $basePath = base_path();
        } catch (\Throwable) {
            $basePath = getcwd();
        }
        $projectPint = $basePath.'/vendor/bin/pint';
        if (file_exists($projectPint)) {
            return $projectPint;
        }

        // Fall back to the package's own dependency
        $packagePint = dirname(__DIR__, 2).'/vendor/bin/pint';
        if (file_exists($packagePint)) {
            return $packagePint;
        }

        throw new RuntimeException('Laravel Pint not found. Install it with: composer require laravel/pint');
    }
}
