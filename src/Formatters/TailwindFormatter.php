<?php

namespace JoelStein\BladeFormatter\Formatters;

use RuntimeException;
use Symfony\Component\Process\Process;

class TailwindFormatter
{
    public function format(string $content, string $prettierPath = 'npx'): string
    {
        // Step 1: Sort standard class="..." attributes
        $result = $this->sortClassAttributes($prettierPath, $content);

        // Step 2: Sort classes inside @class([...]) directives
        $result = $this->sortAtClassDirectives($prettierPath, $result);

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
        preg_match_all('/\bclass="([^"]*)"/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $classStrings[] = $match[1];
        }

        // From @class([...]) directives
        preg_match_all('/@class\(\[[\s\S]*?\]\)/', $content, $directiveMatches, PREG_SET_ORDER);
        $classStringPattern = '/([\'"])((?:(?!\1).)*)\1(?:\s*=>)?/';
        foreach ($directiveMatches as $match) {
            preg_match_all($classStringPattern, $match[0], $stringMatches, PREG_SET_ORDER);
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
        $content = preg_replace_callback('/\bclass="([^"]*)"/', function (array $match) use ($sortMap): string {
            $sorted = $sortMap[$match[1]] ?? null;

            return $sorted !== null ? 'class="'.$sorted.'"' : $match[0];
        }, $content) ?? $content;

        // Replace in @class([...]) directives
        $classStringPattern = '/([\'"])((?:(?!\1).)*)\1(?:\s*=>)?/';
        $content = (string) preg_replace_callback('/@class\(\[[\s\S]*?\]\)/', function (array $block) use ($classStringPattern, $sortMap): string {
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

        return $content;
    }

    /**
     * Sort classes in standard class="..." attributes.
     */
    private function sortClassAttributes(string $prettierPath, string $content): string
    {
        preg_match_all('/\bclass="([^"]*)"/', $content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return $content;
        }

        $classValues = array_map(fn (array $m): string => $m[1], $matches);
        $sortedValues = $this->sortClassStrings($prettierPath, $classValues);

        if ($sortedValues === null) {
            return $content;
        }

        $replacements = [];
        for ($i = 0; $i < count($classValues); $i++) {
            if ($classValues[$i] !== $sortedValues[$i]) {
                $replacements[$classValues[$i]] = $sortedValues[$i];
            }
        }

        if ($replacements === []) {
            return $content;
        }

        return preg_replace_callback('/\bclass="([^"]*)"/', function (array $match) use ($replacements): string {
            $sorted = $replacements[$match[1]] ?? null;

            return $sorted !== null ? 'class="'.$sorted.'"' : $match[0];
        }, $content) ?? $content;
    }

    /**
     * Sort classes within @class([...]) directives.
     */
    private function sortAtClassDirectives(string $prettierPath, string $content): string
    {
        preg_match_all('/@class\(\[[\s\S]*?\]\)/', $content, $matches, PREG_SET_ORDER);

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

        return (string) preg_replace_callback('/@class\(\[[\s\S]*?\]\)/', function (array $block) use ($classStringPattern, $sortMap): string {
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
     * Sort an array of class strings using Prettier with the Tailwind plugin.
     *
     * @param  list<string>  $classStrings
     * @return list<string>|null Sorted class strings, or null on failure
     */
    private function sortClassStrings(string $prettierPath, array $classStrings): ?array
    {
        if ($classStrings === []) {
            return [];
        }

        $lines = array_map(
            fn (string $cs, int $i): string => '<div id="cs'.$i.'" class="'.$cs.'"></div>',
            $classStrings,
            array_keys($classStrings)
        );

        $sorted = $this->runPrettier($prettierPath, implode("\n", $lines));

        preg_match_all('/class="([^"]*)"/', $sorted, $extractMatches);

        if (count($extractMatches[1]) !== count($classStrings)) {
            return null;
        }

        return $extractMatches[1];
    }

    /**
     * Run Prettier with the Tailwind CSS plugin on content via a temp file.
     */
    private function runPrettier(string $prettierPath, string $content): string
    {
        $tmpDir = sys_get_temp_dir().'/blade-fmt-tw-'.uniqid();
        mkdir($tmpDir);
        $tmpFile = $tmpDir.'/template.html';

        try {
            file_put_contents($tmpFile, $content);

            $command = self::buildPrettierCommand($prettierPath, $tmpFile);

            $process = new Process($command);
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Prettier failed: '.($process->getErrorOutput() ?: $process->getOutput()));
            }

            return (string) file_get_contents($tmpFile);
        } finally {
            @unlink($tmpFile);
            @rmdir($tmpDir);
        }
    }

    /**
     * Build the Prettier command array.
     *
     * @return list<string>
     */
    public static function buildPrettierCommand(string $prettierPath, string $filePath): array
    {
        $commonArgs = [$filePath, '--write', '--plugin', 'prettier-plugin-tailwindcss', '--print-width', '9999'];

        if ($prettierPath !== 'npx') {
            return [$prettierPath, ...$commonArgs];
        }

        return ['npx', 'prettier', ...$commonArgs];
    }
}
