<?php

namespace BladeFormatter\Formatters;

use RuntimeException;
use Symfony\Component\Process\Process;

class PintFormatter
{
    /** The Pint release that introduced the --parallel option */
    private const PARALLEL_MIN_VERSION = '1.23.0';

    /**
     * Chunks a batch needs before Pint is run in parallel. Starting the worker
     * processes costs about as much as formatting a dozen files, so a smaller
     * batch — a single file formatted on save — is quicker in one process.
     */
    private const PARALLEL_MIN_CHUNKS = 16;

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
        return $this->finishBatch($this->startBatch($phpChunks, $configPath), $ensureClosingTag);
    }

    /**
     * Start a Pint run over the chunks without waiting for it, so it can
     * overlap with other subprocesses. Pass the result to finishBatch().
     *
     * @param  array<string, string>  $phpChunks  Keyed by identifier
     * @return array{process: Process, dir: string, keyMap: array<string, string>}|null Null when there is nothing to format
     */
    public function startBatch(array $phpChunks, ?string $configPath = null): ?array
    {
        if ($phpChunks === []) {
            return null;
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

            $process = new Process(self::buildPintCommand($pintPath, $tmpDir, count($phpChunks), $configPath));
            $process->setTimeout(30);
            $process->start();
        } catch (\Throwable $e) {
            self::removeTmpDir($tmpDir);

            throw $e;
        }

        return ['process' => $process, 'dir' => $tmpDir, 'keyMap' => $keyMap];
    }

    /**
     * Wait for a Pint run started by startBatch() and collect its results.
     *
     * @param  array{process: Process, dir: string, keyMap: array<string, string>}|null  $pending
     * @param  bool  $ensureClosingTag  Append closing ?> if missing (for SFC sections)
     * @return array<string, string> Formatted chunks, keyed as they were given
     */
    public function finishBatch(?array $pending, bool $ensureClosingTag = true): array
    {
        if ($pending === null) {
            return [];
        }

        ['process' => $process, 'dir' => $tmpDir, 'keyMap' => $keyMap] = $pending;

        try {
            $process->wait();

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
            self::removeTmpDir($tmpDir);
        }
    }

    /**
     * Format @php block code with Pint.
     *
     * When expandImports is true (for SFCs), use statements are expanded back
     * to FQCNs so they can be hoisted to the PHP section. When false (for
     * regular Blade files), use statements are left as-is.
     *
     * @param  array<string, string>  $phpChunks  Keyed by identifier, raw PHP without <?php prefix
     * @return array<string, string>  Formatted chunks, same keys
     */
    public function formatPhpBlockBatch(array $phpChunks, ?string $configPath = null, bool $expandImports = false): array
    {
        return $this->finishPhpBlockBatch(
            $this->startPhpBlockBatch($phpChunks, $configPath),
            $expandImports ? array_keys($phpChunks) : [],
        );
    }

    /**
     * Start a Pint run over @php block contents without waiting for it.
     * Pass the result to finishPhpBlockBatch().
     *
     * @param  array<string, string>  $phpChunks  Keyed by identifier, raw PHP without <?php prefix
     * @return array{process: Process, dir: string, keyMap: array<string, string>, config: string}|null Null when there is nothing to format
     */
    public function startPhpBlockBatch(array $phpChunks, ?string $configPath = null): ?array
    {
        if ($phpChunks === []) {
            return null;
        }

        // Wrap each chunk with <?php for Pint
        $wrapped = [];
        foreach ($phpChunks as $key => $code) {
            $wrapped[$key] = "<?php\n".$code;
        }

        $blockConfig = $this->buildPhpBlockConfig($configPath);

        try {
            $pending = $this->startBatch($wrapped, $blockConfig);
        } catch (\Throwable $e) {
            @unlink($blockConfig);

            throw $e;
        }

        if ($pending === null) {
            @unlink($blockConfig);

            return null;
        }

        return [...$pending, 'config' => $blockConfig];
    }

    /**
     * Wait for a run started by startPhpBlockBatch() and collect its results.
     *
     * @param  array{process: Process, dir: string, keyMap: array<string, string>, config: string}|null  $pending
     * @param  list<string>  $expandImportsFor  Keys of the chunks whose use statements are expanded to FQCNs
     * @return array<string, string> Formatted chunks, keyed as they were given
     */
    public function finishPhpBlockBatch(?array $pending, array $expandImportsFor = []): array
    {
        if ($pending === null) {
            return [];
        }

        try {
            $formatted = $this->finishBatch($pending, ensureClosingTag: false);
        } finally {
            @unlink($pending['config']);
        }

        $expand = array_fill_keys($expandImportsFor, true);

        // Strip <?php prefix and optionally expand use statements to FQCNs
        $results = [];
        foreach ($formatted as $key => $code) {
            $code = (string) preg_replace('/^<\?php\s*\n?/', '', $code);
            if (isset($expand[$key])) {
                $code = $this->expandUseStatements($code);
                $code = $this->stripLeadingBackslashes($code);
            }
            $results[$key] = $code;
        }

        return $results;
    }

    private static function removeTmpDir(string $tmpDir): void
    {
        foreach (glob($tmpDir.'/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);
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
            $replacements[$shortName] = $fqcn;

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

    /**
     * Strip leading backslashes from fully qualified class names.
     *
     * In Blade @php blocks (global namespace), App\Foo and \App\Foo are
     * equivalent. The backslash-free form is the Laravel convention.
     */
    private function stripLeadingBackslashes(string $code): string
    {
        // Match \ClassName (uppercase after backslash, not inside strings)
        return (string) preg_replace('/(?<![\\\\a-zA-Z])\\\\([A-Z])/', '$1', $code);
    }

    /**
     * Build the Pint command array.
     *
     * A large batch is formatted in parallel when the Pint being run supports it.
     *
     * @return list<string>
     */
    public static function buildPintCommand(string $pintPath, string $directory, int $chunkCount, ?string $configPath = null): array
    {
        $command = [$pintPath, $directory, '--quiet'];

        if ($configPath !== null) {
            $command[] = '--config';
            $command[] = $configPath;
        }

        if ($chunkCount >= self::PARALLEL_MIN_CHUNKS && self::supportsParallel($pintPath)) {
            $command[] = '--parallel';
        }

        return $command;
    }

    /**
     * Whether the Pint at the given path accepts the --parallel option.
     *
     * An older Pint rejects the option outright, and its exit status for that
     * is the same one it uses for "files were changed", so support is decided
     * up front from the installed version rather than by trying it.
     */
    public static function supportsParallel(string $pintPath): bool
    {
        $version = self::installedPintVersion($pintPath);

        return $version !== null && version_compare($version, self::PARALLEL_MIN_VERSION, '>=');
    }

    /**
     * The version of the Pint at the given path, read from the Composer
     * metadata of the vendor directory it lives in (`vendor/bin/pint` →
     * `vendor/composer/installed.php`). Null when it cannot be determined.
     */
    public static function installedPintVersion(string $pintPath): ?string
    {
        $installedPath = dirname($pintPath, 2).'/composer/installed.php';

        if (! is_file($installedPath)) {
            return null;
        }

        $installed = require $installedPath;

        if (! is_array($installed) || ! is_array($installed['versions'] ?? null)) {
            return null;
        }

        $pint = $installed['versions']['laravel/pint'] ?? null;
        $version = is_array($pint) ? ($pint['version'] ?? null) : null;

        return is_string($version) ? $version : null;
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
