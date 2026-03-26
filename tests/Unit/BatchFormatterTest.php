<?php

namespace Tests\Unit;

use BladeFormatter\BatchFormatter;
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

    #[Test]
    public function it_skips_indentation_for_markdown_mail_templates(): void
    {
        $formatter = new BatchFormatter(
            enablePint: false,
            enableTailwindSort: false,
        );

        $content = "<x-mail::message :unsubscribeUrl=\"\$url\">\n# Hello\n\nContent here.\n\n<x-mail::button :url=\"\$url\">\nView\n</x-mail::button>\n\n</x-mail::message>";

        $files = [
            '/tmp/mail.blade.php' => $content,
        ];

        $results = $formatter->formatBatch($files);

        // Content should NOT be indented — Markdown whitespace is significant
        $this->assertSame($content, $results['/tmp/mail.blade.php']);
    }

    #[Test]
    public function it_preserves_fqcns_in_php_blocks(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
@php
    $status = $record->status;
    $isPending = $status === \App\Enums\Status::Pending;
    $isComplete = $status === \App\Enums\Status::Complete;
@endphp
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        $this->assertStringNotContainsString('use App\\', $result);
        $this->assertStringContainsString('\App\Enums\Status::Pending', $result);
        $this->assertStringContainsString('\App\Enums\Status::Complete', $result);
    }

    #[Test]
    public function it_expands_use_statements_in_php_blocks_to_fqcns(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
@php
    use App\Enums\Status;

    $isPending = $status === Status::Pending;
    $isComplete = $status === Status::Complete;
@endphp
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        $this->assertStringNotContainsString('use App\\', $result);
        $this->assertStringContainsString('\App\Enums\Status::Pending', $result);
        $this->assertStringContainsString('\App\Enums\Status::Complete', $result);
    }

    #[Test]
    public function it_expands_aliased_use_statements_in_php_blocks(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
@php
    use App\Enums\Status as S;

    $isPending = $status === S::Pending;
@endphp
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        $this->assertStringNotContainsString('use App\\', $result);
        $this->assertStringNotContainsString('S::', $result);
        $this->assertStringContainsString('\App\Enums\Status::Pending', $result);
    }
}
