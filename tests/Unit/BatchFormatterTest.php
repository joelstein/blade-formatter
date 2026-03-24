<?php

namespace JoelStein\BladeFormatter\Tests\Unit;

use JoelStein\BladeFormatter\BatchFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BatchFormatterTest extends TestCase
{
    #[Test]
    public function it_fires_callback_for_each_file(): void
    {
        $formatter = new BatchFormatter(
            enablePint: false,
            enableTailwindSort: false,
        );

        $files = [
            '/tmp/a.blade.php' => "<div>\n<p>A</p>\n</div>",
            '/tmp/b.blade.php' => "<div>\n    <p>B</p>\n</div>",
        ];

        $callbackPaths = [];

        $formatter->formatBatch($files, function (string $path, string $formatted) use (&$callbackPaths) {
            $callbackPaths[] = $path;
        });

        $this->assertSame(['/tmp/a.blade.php', '/tmp/b.blade.php'], $callbackPaths);
    }

    #[Test]
    public function callback_receives_formatted_content(): void
    {
        $formatter = new BatchFormatter(
            enablePint: false,
            enableTailwindSort: false,
        );

        $files = [
            '/tmp/test.blade.php' => "<div>\n<p>Hello</p>\n</div>",
        ];

        $callbackResults = [];

        $results = $formatter->formatBatch($files, function (string $path, string $formatted) use (&$callbackResults) {
            $callbackResults[$path] = $formatted;
        });

        // Callback content should match the returned results
        $this->assertSame($results, $callbackResults);
        // Content should be indented
        $this->assertStringContainsString('    <p>Hello</p>', $callbackResults['/tmp/test.blade.php']);
    }

    #[Test]
    public function it_works_without_callback(): void
    {
        $formatter = new BatchFormatter(
            enablePint: false,
            enableTailwindSort: false,
        );

        $files = [
            '/tmp/test.blade.php' => "<div>\n<p>Hello</p>\n</div>",
        ];

        $results = $formatter->formatBatch($files);

        $this->assertStringContainsString('    <p>Hello</p>', $results['/tmp/test.blade.php']);
    }
}
