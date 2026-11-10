<?php

namespace BladeFormatter\Formatters;

use RuntimeException;
use Symfony\Component\Process\Process;

class TailwindFormatter
{
    /**
     * Pattern to match Blade directives and expressions inside class attribute values.
     *
     * Only treats `@word` as a Blade directive when it is:
     *   - a known control-flow keyword (e.g. @if, @endif, @else), OR
     *   - any `@word(...)` form with parentheses (e.g. @if($x), @can('foo')).
     *
     * Bare `@word` tokens not in the allow-list are left as class text so Tailwind
     * container-query variants like `@sm:flex`, `@container/sidebar`, and `@max-md:hidden`
     * pass through to the Tailwind sorter intact.
     */
    private const BLADE_IN_CLASS_PATTERN = '/(@\w+\((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*\)|@(?:if|elseif|else|endif|unless|endunless|isset|endisset|empty|endempty|auth|endauth|guest|endguest|hasSection|sectionMissing|can|cannot|canany|elsecan|elsecannot|endcan|endcannot|endcanany|error|enderror|production|endproduction|env|endenv|once|endonce|feature|endfeature|switch|case|break|default|endswitch|verbatim|endverbatim)\b|\{\{.*?\}\}|\{!!.*?!!\})/';

    /** Pattern to match @class([...]) directives and $var->class([...]) method calls */
    private const CLASS_ARRAY_CALL_PATTERN = '/(?:@class|\$\w+->class)\(\[[\s\S]*?\]\)/';

    /**
     * Class strings written to each file handed to Prettier. Its HTML printer
     * slows down sharply as one document grows, so a large batch is spread
     * over many small files rather than written as a single huge one.
     */
    private const CLASS_STRINGS_PER_FILE = 500;

    /** Class strings handled by each Prettier process before another is started */
    private const CLASS_STRINGS_PER_PROCESS = 5000;

    private const MAX_PRETTIER_PROCESSES = 4;

    public function format(string $content, string $prettierPath = 'node_modules/.bin/prettier'): string
    {
        // Step 1: Sort standard class="..." attributes
        $result = $this->sortClassAttributes($prettierPath, $content);

        // Step 2: Sort classes inside @class([...]) directives and $attributes->class([...]) method calls
        $result = $this->sortClassArrayCalls($prettierPath, $result);

        // Step 3: Sort classes inside :class="..." and x-bind:class="..." bindings
        $result = $this->sortBoundClassAttributes($prettierPath, $result);

        return $result;
    }

    /**
     * Extract all class strings from content (both class="" and @class([])).
     *
     * @return list<string>
     */
    public function extractClassStrings(string $content): array
    {
        $classStrings = [];

        // From class="..." attributes
        preg_match_all('/(?<!:)\bclass="([^"]*)"/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (preg_match(self::BLADE_IN_CLASS_PATTERN, $match[1])) {
                $parts = preg_split(self::BLADE_IN_CLASS_PATTERN, $match[1], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$match[1]];
                foreach ($parts as $i => $part) {
                    if ($i % 2 === 0 && trim($part) !== '') {
                        $classStrings[] = trim($part);
                    }
                }
            } else {
                $classStrings[] = $match[1];
            }
        }

        // From @class([...]) directives and $attributes->class([...]) method calls
        preg_match_all(self::CLASS_ARRAY_CALL_PATTERN, $content, $directiveMatches, PREG_SET_ORDER);
        $classStringPattern = '/([\'"])((?:(?!\1).)*)\1(?:\s*=>)?/';
        foreach ($directiveMatches as $match) {
            preg_match_all($classStringPattern, $match[0], $stringMatches, PREG_SET_ORDER);
            foreach ($stringMatches as $stringMatch) {
                $classStrings[] = $stringMatch[2];
            }
        }

        // From :class="..." and x-bind:class="..." bindings (only class values, not JS comparison values)
        preg_match_all('/(?::class|x-bind:class)="([^"]*)"/', $content, $boundMatches, PREG_SET_ORDER);
        $boundClassStringPattern = '/[?:{,]\s*([\'"])((?:(?!\1).)*)\1/';
        foreach ($boundMatches as $match) {
            preg_match_all($boundClassStringPattern, $match[1], $stringMatches, PREG_SET_ORDER);
            foreach ($stringMatches as $stringMatch) {
                $classStrings[] = $stringMatch[2];
            }
        }

        return $classStrings;
    }

    /**
     * Sort a batch of class strings in one Prettier call.
     *
     * @param  list<string>  $classStrings
     * @return list<string>|null Sorted class strings, or null on failure
     */
    public function sortClassStringsBatch(string $prettierPath, array $classStrings): ?array
    {
        return $this->sortClassStrings($prettierPath, $classStrings);
    }

    /**
     * Apply pre-sorted class strings back to content.
     *
     * @param  array<string, string>  $sortMap  Original → sorted class string
     */
    public function applySortedClasses(string $content, array $sortMap): string
    {
        // Replace in class="..." attributes
        $content = preg_replace_callback('/(?<!:)\bclass="([^"]*)"/', function (array $match) use ($sortMap): string {
            if (preg_match(self::BLADE_IN_CLASS_PATTERN, $match[1])) {
                return $this->rebuildBladeClassAttribute($match[1], $sortMap);
            }

            $sorted = $sortMap[$match[1]] ?? null;

            return $sorted !== null ? 'class="'.$sorted.'"' : $match[0];
        }, $content) ?? $content;

        // Replace in @class([...]) directives and $attributes->class([...]) method calls
        $classStringPattern = '/([\'"])((?:(?!\1).)*)\1(?:\s*=>)?/';
        $content = (string) preg_replace_callback(self::CLASS_ARRAY_CALL_PATTERN, function (array $block) use ($classStringPattern, $sortMap): string {
            return (string) preg_replace_callback($classStringPattern, function (array $match) use ($sortMap): string {
                $quote = $match[1];
                $classValue = $match[2];
                $sorted = $sortMap[$classValue] ?? null;

                if ($sorted === null || $sorted === $classValue) {
                    return $match[0];
                }

                return str_replace($quote.$classValue.$quote, $quote.$sorted.$quote, $match[0]);
            }, $block[0]);
        }, $content);

        // Replace in :class="..." and x-bind:class="..." bindings
        $boundClassStringPattern = '/([?:{,]\s*)([\'"])((?:(?!\2).)*)\2/';
        $content = (string) preg_replace_callback('/(?::class|x-bind:class)="([^"]*)"/', function (array $block) use ($boundClassStringPattern, $sortMap): string {
            return (string) preg_replace_callback($boundClassStringPattern, function (array $match) use ($sortMap): string {
                $prefix = $match[1];
                $quote = $match[2];
                $classValue = $match[3];
                $sorted = $sortMap[$classValue] ?? null;

                if ($sorted === null || $sorted === $classValue) {
                    return $match[0];
                }

                return $prefix.$quote.$sorted.$quote;
            }, $block[0]);
        }, $content);

        return $content;
    }

    /**
     * Sort classes in standard class="..." attributes.
     */
    private function sortClassAttributes(string $prettierPath, string $content): string
    {
        preg_match_all('/(?<!:)\bclass="([^"]*)"/', $content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return $content;
        }

        // Collect all class strings that need sorting, splitting Blade-containing values into segments
        $allClassStrings = [];
        /** @var array<int, int> Maps match index → allClassStrings index (plain classes) */
        $plainIndices = [];
        /** @var array<int, array{parts: list<string>, segmentIndices: array<int, int>}> Blade-containing classes */
        $bladeEntries = [];

        foreach ($matches as $j => $match) {
            $classValue = $match[1];

            if (preg_match(self::BLADE_IN_CLASS_PATTERN, $classValue)) {
                $parts = preg_split(self::BLADE_IN_CLASS_PATTERN, $classValue, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$classValue];
                $segmentIndices = [];
                foreach ($parts as $i => $part) {
                    if ($i % 2 === 0 && trim($part) !== '') {
                        $segmentIndices[$i] = count($allClassStrings);
                        $allClassStrings[] = trim($part);
                    }
                }
                $bladeEntries[$j] = ['parts' => $parts, 'segmentIndices' => $segmentIndices];
            } else {
                $plainIndices[$j] = count($allClassStrings);
                $allClassStrings[] = $classValue;
            }
        }

        if ($allClassStrings === []) {
            return $content;
        }

        $sortedValues = $this->sortClassStrings($prettierPath, $allClassStrings);

        if ($sortedValues === null) {
            return $content;
        }

        // Build replacement map
        $replacements = [];
        foreach ($matches as $j => $match) {
            $original = $match[1];

            if (isset($bladeEntries[$j])) {
                $entry = $bladeEntries[$j];
                $segments = [];
                foreach ($entry['parts'] as $i => $part) {
                    if ($i % 2 === 0) {
                        $trimmed = trim($part);
                        if ($trimmed !== '') {
                            $segments[] = $sortedValues[$entry['segmentIndices'][$i]];
                        }
                    } else {
                        $segments[] = $part;
                    }
                }
                $sorted = implode(' ', $segments);
                if ($sorted !== $original) {
                    $replacements[$original] = $sorted;
                }
            } elseif (isset($plainIndices[$j])) {
                $idx = $plainIndices[$j];
                if ($allClassStrings[$idx] !== $sortedValues[$idx]) {
                    $replacements[$original] = $sortedValues[$idx];
                }
            }
        }

        if ($replacements === []) {
            return $content;
        }

        return preg_replace_callback('/(?<!:)\bclass="([^"]*)"/', function (array $match) use ($replacements): string {
            $sorted = $replacements[$match[1]] ?? null;

            return $sorted !== null ? 'class="'.$sorted.'"' : $match[0];
        }, $content) ?? $content;
    }

    /**
     * Sort classes within @class([...]) directives and $var->class([...]) method calls.
     */
    private function sortClassArrayCalls(string $prettierPath, string $content): string
    {
        preg_match_all(self::CLASS_ARRAY_CALL_PATTERN, $content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return $content;
        }

        $classStrings = [];
        $classStringPattern = '/([\'"])((?:(?!\1).)*)\1(?:\s*=>)?/';

        foreach ($matches as $match) {
            preg_match_all($classStringPattern, $match[0], $stringMatches, PREG_SET_ORDER);

            foreach ($stringMatches as $stringMatch) {
                $classStrings[] = $stringMatch[2];
            }
        }

        if (empty($classStrings)) {
            return $content;
        }

        $sortedClasses = $this->sortClassStrings($prettierPath, $classStrings);

        if ($sortedClasses === null) {
            return $content;
        }

        $sortMap = [];
        for ($i = 0; $i < count($classStrings); $i++) {
            $sortMap[$classStrings[$i]] = $sortedClasses[$i];
        }

        return (string) preg_replace_callback(self::CLASS_ARRAY_CALL_PATTERN, function (array $block) use ($classStringPattern, $sortMap): string {
            return (string) preg_replace_callback($classStringPattern, function (array $match) use ($sortMap): string {
                $quote = $match[1];
                $classValue = $match[2];
                $sorted = $sortMap[$classValue] ?? null;

                if ($sorted === null || $sorted === $classValue) {
                    return $match[0];
                }

                return str_replace($quote.$classValue.$quote, $quote.$sorted.$quote, $match[0]);
            }, $block[0]);
        }, $content);
    }

    /**
     * Sort classes within :class="..." and x-bind:class="..." bindings.
     */
    private function sortBoundClassAttributes(string $prettierPath, string $content): string
    {
        preg_match_all('/(?::class|x-bind:class)="([^"]*)"/', $content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return $content;
        }

        $classStrings = [];
        $boundClassStringPattern = '/[?:{,]\s*([\'"])((?:(?!\1).)*)\1/';

        foreach ($matches as $match) {
            preg_match_all($boundClassStringPattern, $match[1], $stringMatches, PREG_SET_ORDER);

            foreach ($stringMatches as $stringMatch) {
                $classStrings[] = $stringMatch[2];
            }
        }

        if (empty($classStrings)) {
            return $content;
        }

        $sortedClasses = $this->sortClassStrings($prettierPath, $classStrings);

        if ($sortedClasses === null) {
            return $content;
        }

        $sortMap = [];
        for ($i = 0; $i < count($classStrings); $i++) {
            $sortMap[$classStrings[$i]] = $sortedClasses[$i];
        }

        $boundReplacePattern = '/([?:{,]\s*)([\'"])((?:(?!\2).)*)\2/';

        return (string) preg_replace_callback('/(?::class|x-bind:class)="([^"]*)"/', function (array $block) use ($boundReplacePattern, $sortMap): string {
            return (string) preg_replace_callback($boundReplacePattern, function (array $match) use ($sortMap): string {
                $prefix = $match[1];
                $quote = $match[2];
                $classValue = $match[3];
                $sorted = $sortMap[$classValue] ?? null;

                if ($sorted === null || $sorted === $classValue) {
                    return $match[0];
                }

                return $prefix.$quote.$sorted.$quote;
            }, $block[0]);
        }, $content);
    }

    /**
     * Sort an array of class strings using Prettier with the Tailwind plugin.
     *
     * @param  list<string>  $classStrings
     * @return list<string>|null Sorted class strings, or null on failure
     */
    private function sortClassStrings(string $prettierPath, array $classStrings): ?array
    {
        return $this->finishSort($this->startSort($prettierPath, $classStrings));
    }

    /**
     * Start sorting class strings with Prettier without waiting for it, so it
     * can overlap with other subprocesses. Pass the result to finishSort().
     *
     * @param  list<string>  $classStrings
     * @return array{processes: list<Process>, dir: string, files: list<array{path: string, count: int}>}|null Null when there is nothing to sort
     */
    public function startSort(string $prettierPath, array $classStrings): ?array
    {
        if ($classStrings === []) {
            return null;
        }

        $tmpDir = sys_get_temp_dir().'/blade-fmt-tw-'.uniqid();
        mkdir($tmpDir);

        $files = [];
        $processes = [];

        try {
            foreach (array_chunk($classStrings, self::CLASS_STRINGS_PER_FILE) as $index => $chunk) {
                $lines = array_map(
                    fn (string $cs, int $i): string => '<div id="cs'.$i.'" class="'.$cs.'"></div>',
                    $chunk,
                    array_keys($chunk)
                );

                $path = $tmpDir.'/template'.$index.'.html';
                file_put_contents($path, implode("\n", $lines));
                $files[] = ['path' => $path, 'count' => count($chunk)];
            }

            $processCount = min(
                self::MAX_PRETTIER_PROCESSES,
                (int) ceil(count($classStrings) / self::CLASS_STRINGS_PER_PROCESS),
            );
            $paths = array_column($files, 'path');

            foreach (array_chunk($paths, max(1, (int) ceil(count($paths) / $processCount))) as $group) {
                $process = new Process(self::buildPrettierCommand($prettierPath, $group));
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
        } catch (\Throwable $e) {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            self::removeTmpDir($tmpDir);

            throw $e;
        }

        return ['processes' => $processes, 'dir' => $tmpDir, 'files' => $files];
    }

    /**
     * Wait for a sort started by startSort() and collect its results.
     *
     * @param  array{processes: list<Process>, dir: string, files: list<array{path: string, count: int}>}|null  $pending
     * @return list<string>|null Sorted class strings in the order they were given, or null if Prettier's output could not be matched back to them
     */
    public function finishSort(?array $pending): ?array
    {
        if ($pending === null) {
            return [];
        }

        try {
            foreach ($pending['processes'] as $process) {
                $process->wait();

                if (! $process->isSuccessful()) {
                    throw new RuntimeException('Prettier failed: '.($process->getErrorOutput() ?: $process->getOutput()));
                }
            }

            $sorted = [];
            foreach ($pending['files'] as $file) {
                preg_match_all('/class="([^"]*)"/', (string) file_get_contents($file['path']), $extractMatches);

                if (count($extractMatches[1]) !== $file['count']) {
                    return null;
                }

                foreach ($extractMatches[1] as $classString) {
                    $sorted[] = $classString;
                }
            }

            return $sorted;
        } finally {
            foreach ($pending['processes'] as $process) {
                $process->stop(0);
            }
            self::removeTmpDir($pending['dir']);
        }
    }

    private static function removeTmpDir(string $tmpDir): void
    {
        foreach (glob($tmpDir.'/*.html') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);
    }

    /**
     * Rebuild a class attribute that contains Blade directives, sorting only the class segments.
     *
     * @param  array<string, string>  $sortMap
     */
    private function rebuildBladeClassAttribute(string $classValue, array $sortMap): string
    {
        $parts = preg_split(self::BLADE_IN_CLASS_PATTERN, $classValue, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$classValue];
        $segments = [];

        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $segments[] = $sortMap[$trimmed] ?? $trimmed;
                }
            } else {
                $segments[] = $part;
            }
        }

        return 'class="'.implode(' ', $segments).'"';
    }

    /**
     * Build the Prettier command array.
     *
     * @param  string|list<string>  $filePaths
     * @return list<string>
     */
    public static function buildPrettierCommand(string $prettierPath, string|array $filePaths): array
    {
        $commonArgs = [...(array) $filePaths, '--write', '--plugin', 'prettier-plugin-tailwindcss', '--print-width', '9999'];

        if ($prettierPath !== 'npx') {
            return [$prettierPath, ...$commonArgs];
        }

        return ['npx', 'prettier', ...$commonArgs];
    }
}
