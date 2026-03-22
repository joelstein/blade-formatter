<?php

namespace JoelStein\BladeFormatter;

use JoelStein\BladeFormatter\Formatters\IndentationFormatter;
use JoelStein\BladeFormatter\Formatters\PintFormatter;
use JoelStein\BladeFormatter\Formatters\TailwindFormatter;

class Formatter
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private bool $enablePint = true,
        private bool $enableTailwindSort = true,
        private bool $enableIndentation = true,
        private int $indentSize = 4,
        private ?string $pintConfigPath = null,
        private string $prettierPath = 'npx',
    ) {}

    public static function fromConfig(): self
    {
        /** @var bool $enablePint */
        $enablePint = config('blade-formatter.enable_pint', true);
        /** @var bool $enableTailwindSort */
        $enableTailwindSort = config('blade-formatter.enable_tailwind_sort', true);
        /** @var bool $enableIndentation */
        $enableIndentation = config('blade-formatter.enable_indentation', true);
        /** @var int $indentSize */
        $indentSize = config('blade-formatter.indent_size', 4);
        /** @var string|null $pintConfigPath */
        $pintConfigPath = config('blade-formatter.pint_config_path');
        /** @var string $prettierPath */
        $prettierPath = config('blade-formatter.prettier_path', 'npx');

        return new self(
            enablePint: $enablePint,
            enableTailwindSort: $enableTailwindSort,
            enableIndentation: $enableIndentation,
            indentSize: $indentSize,
            pintConfigPath: $pintConfigPath,
            prettierPath: $prettierPath,
        );
    }

    public function format(string $content): string
    {
        $this->warnings = [];

        ['php' => $php, 'blade' => $blade, 'isSfc' => $isSfc] = Parser::parseSfc($content);

        $formattedPhp = $php;
        $formattedBlade = $isSfc ? $blade : $content;

        // Step 1: Format PHP with Pint (SFCs only)
        if ($this->enablePint && $isSfc) {
            try {
                $formattedPhp = (new PintFormatter)->format($formattedPhp, $this->pintConfigPath);
            } catch (\Throwable $e) {
                $this->warnings[] = 'Pint skipped: '.$e->getMessage();
            }
        }

        // Step 2: Sort Tailwind classes
        if ($this->enableTailwindSort) {
            try {
                $formattedBlade = (new TailwindFormatter)->format($formattedBlade, $this->prettierPath);
            } catch (\Throwable $e) {
                $this->warnings[] = 'Tailwind sorting skipped: '.$e->getMessage();
            }
        }

        // Step 3: Auto-indent Blade
        if ($this->enableIndentation) {
            $formattedBlade = (new IndentationFormatter)->format($formattedBlade, $this->indentSize);
        }

        $result = $isSfc
            ? Parser::assembleSfc($formattedPhp, $formattedBlade)
            : $formattedBlade;

        // Step 4: Collapse multiple consecutive blank lines into one
        return (string) preg_replace('/\n{3,}/', "\n\n", $result);
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
