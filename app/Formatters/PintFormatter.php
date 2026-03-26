<?php

namespace BladeFormatter\Formatters;

use RuntimeException;
use Symfony\Component\Process\Process;

class PintFormatter
{
    public function format(string $phpCode, ?string $configPath = null): string
    {
        $results = $this->formatBatch(['single' => $phpCode], $configPath);

        return $results['single'];
    }

    /**
     * Format multiple PHP chunks in a single Pint invocation.
     *
     * @param  array<string, string>  $phpChunks  Keyed by identifier
     * @param  bool  $ensureClosingTag  Append closing ?> if missing (for SFC sections)
     * @return array<string, string> Formatted chunks, same keys
     */
    public function formatBatch(array $phpChunks, ?string $configPath = null, bool $ensureClosingTag = true): array
    {
        if ($phpChunks === []) {
            return [];
        }

        $pintPath = self::resolvePintPath();
        $tmpDir = sys_get_temp_dir().'/blade-fmt-'.uniqid();
        mkdir($tmpDir);

        try {
            $keyMap = [];
            $index = 0;
            foreach ($phpChunks as $key => $phpCode) {
                $filename = $index.'.php';
                $keyMap[$filename] = $key;
                file_put_contents($tmpDir.'/'.$filename, $phpCode);
                $index++;
            }

            $command = [$pintPath, $tmpDir, '--quiet'];
            if ($configPath !== null) {
                $command[] = '--config';
                $command[] = $configPath;
            }

            $process = new Process($command);
            $process->setTimeout(30);
            $process->run();

            // Pint exits with 1 when it makes changes, which is fine
            if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
                throw new RuntimeException('Pint failed: '.($process->getErrorOutput() ?: $process->getOutput()));
            }

            $results = [];
            foreach ($keyMap as $filename => $key) {
                $formatted = rtrim((string) file_get_contents($tmpDir.'/'.$filename));

                if ($ensureClosingTag) {
                    // Pint strips the closing PHP tag (PSR-12), but SFCs need it
                    if (! str_ends_with($formatted, '?'.'>')) {
                        $formatted .= "\n".'?'.'>';
                    }
                }

                $results[$key] = $formatted;
            }

            return $results;
        } finally {
            // Clean up temp files
            foreach (glob($tmpDir.'/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Format @php block code: run Pint with FQCN import rules disabled,
     * then expand any use statements back to fully qualified class names.
     *
     * @param  array<string, string>  $phpChunks  Keyed by identifier, raw PHP without <?php prefix
     * @return array<string, string>  Formatted chunks, same keys
     */
    public function formatPhpBlockBatch(array $phpChunks, ?string $configPath = null): array
    {
        if ($phpChunks === []) {
            return [];
        }

        // Wrap each chunk with <?php for Pint
        $wrapped = [];
        foreach ($phpChunks as $key => $code) {
            $wrapped[$key] = "<?php\n".$code;
        }

        $blockConfig = $this->buildPhpBlockConfig($configPath);

        try {
            $formatted = $this->formatBatch($wrapped, $blockConfig, ensureClosingTag: false);
        } finally {
            @unlink($blockConfig);
        }

        // Strip <?php prefix and expand use statements
        $results = [];
        foreach ($formatted as $key => $code) {
            $code = (string) preg_replace('/^<\?php\s*\n?/', '', $code);
            $code = $this->expandUseStatements($code);
            $results[$key] = $code;
        }

        return $results;
    }

    /**
     * Build a temporary Pint config that disables FQCN import rules
     * (which make no sense in isolated @php blocks).
     */
    private function buildPhpBlockConfig(?string $basePath): string
    {
        $config = [];

        if ($basePath !== null && file_exists($basePath)) {
            $config = (array) json_decode((string) file_get_contents($basePath), true);
        }

        $config['rules'] = array_merge((array) ($config['rules'] ?? []), [
            'fully_qualified_strict_types' => false,
            'global_namespace_import' => false,
        ]);

        $tmpConfig = sys_get_temp_dir().'/blade-fmt-pint-'.uniqid().'.json';
        file_put_contents($tmpConfig, json_encode($config));

        return $tmpConfig;
    }

    /**
     * Expand use statements back to FQCNs.
     *
     * Use statements don't make sense in @php blocks since there's no persistent
     * namespace context. This replaces short class names with their fully qualified
     * equivalents and removes the use statements.
     */
    private function expandUseStatements(string $code): string
    {
        // Parse use statements: use Foo\Bar; or use Foo\Bar as Baz;
        if (! preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;$/m', $code, $matches, PREG_SET_ORDER)) {
            return $code;
        }

        $replacements = [];
        foreach ($matches as $match) {
            $fqcn = $match[1];
            $alias = $match[2] ?? null;
            $shortName = $alias ?? substr($fqcn, strrpos($fqcn, '\\') + 1);
            $replacements[$shortName] = '\\'.$fqcn;

            // Remove the use statement and any trailing blank line
            $code = str_replace($match[0]."\n\n", '', $code);
            $code = str_replace($match[0]."\n", '', $code);
            $code = str_replace($match[0], '', $code);
        }

        // Replace short names with FQCNs in all class reference contexts:
        // ClassName::, new ClassName, instanceof ClassName, ClassName $var
        foreach ($replacements as $shortName => $fqcn) {
            $code = (string) preg_replace(
                '/(?<=instanceof\s)\b'.preg_quote($shortName, '/').'\b'
                .'|\b'.preg_quote($shortName, '/').'(?=\s*::|\s*\$)'
                .'|(?<=new\s)\b'.preg_quote($shortName, '/').'\b/',
                $fqcn,
                $code,
            );
        }

        return $code;
    }

    public static function resolvePintPath(): string
    {
        // Check for Pint in the project's vendor directory
        $cwd = (string) getcwd();
        $projectPint = $cwd.'/vendor/bin/pint';
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
