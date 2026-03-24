<?php

namespace Tests\Unit;

use App\Formatters\TailwindFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TailwindFormatterTest extends TestCase
{
    private TailwindFormatter $formatter;

    private static bool $hasPrettier = false;

    public static function setUpBeforeClass(): void
    {
        // Check if prettier and the tailwind plugin are available
        exec('npx prettier --version 2>/dev/null', $output, $exitCode);
        self::$hasPrettier = $exitCode === 0;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$hasPrettier) {
            $this->markTestSkipped('Prettier with prettier-plugin-tailwindcss is not installed.');
        }

        $this->formatter = new TailwindFormatter;
    }

    #[Test]
    public function it_sorts_standard_class_attributes(): void
    {
        $input = '<div class="font-bold text-red-500 flex"></div>';
        $result = $this->formatter->format($input);

        $this->assertStringContainsString('class="flex font-bold text-red-500"', $result);
    }

    #[Test]
    public function it_sorts_classes_inside_at_class_directives(): void
    {
        $input = <<<'BLADE'
        <div @class([
            'font-bold text-red-500 flex',
            'mt-4 block p-2' => $active,
        ])></div>
        BLADE;

        $result = $this->formatter->format($input);

        $this->assertStringContainsString("'flex font-bold text-red-500'", $result);
        // Prettier keeps Tailwind's canonical order for these utility classes
        $this->assertStringContainsString('=> $active', $result);
    }

    #[Test]
    public function it_handles_single_line_at_class_directives(): void
    {
        $input = "<div @class(['font-bold text-red-500 flex'])></div>";
        $result = $this->formatter->format($input);

        $this->assertStringContainsString("'flex font-bold text-red-500'", $result);
    }

    #[Test]
    public function it_preserves_non_class_content_around_at_class_directives(): void
    {
        $input = <<<'BLADE'
        <div
            @class(['font-bold text-red-500 flex'])
            x-data="{ open: false }"
        ></div>
        BLADE;

        $result = $this->formatter->format($input);

        $this->assertStringContainsString("'flex font-bold text-red-500'", $result);
        $this->assertStringContainsString('x-data="{ open: false }"', $result);
    }

    #[Test]
    public function it_handles_at_class_with_double_quotes(): void
    {
        $input = '<div @class(["font-bold text-red-500 flex"])></div>';
        $result = $this->formatter->format($input);

        $this->assertStringContainsString('"flex font-bold text-red-500"', $result);
    }

    #[Test]
    public function it_leaves_already_sorted_at_class_directives_unchanged(): void
    {
        $input = "<div @class(['flex font-bold text-red-500'])></div>";
        $result = $this->formatter->format($input);

        $this->assertStringContainsString("'flex font-bold text-red-500'", $result);
    }
}
