<?php

namespace Tests\Unit;

use App\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    #[Test]
    public function it_parses_a_standard_livewire_sfc(): void
    {
        $content = <<<'BLADE'
        <?php

        use App\Models\User;

        new class extends Component {
            public string $name = '';
        };
        ?>
        <div>
            <h1>Hello</h1>
        </div>
        BLADE;

        $result = Parser::parseSfc($content);

        $this->assertTrue($result['isSfc']);
        $this->assertStringContainsString('<?php', $result['php']);
        $this->assertStringContainsString('?>', $result['php']);
        $this->assertStringContainsString('use App\Models\User', $result['php']);
        $this->assertStringContainsString('<div>', $result['blade']);
        $this->assertStringContainsString('<h1>Hello</h1>', $result['blade']);
    }

    #[Test]
    public function it_returns_is_sfc_false_when_no_php_opening_tag(): void
    {
        $content = <<<'BLADE'
        <div>
            <h1>Hello</h1>
        </div>
        BLADE;

        $result = Parser::parseSfc($content);

        $this->assertFalse($result['isSfc']);
        $this->assertSame('', $result['php']);
        $this->assertSame($content, $result['blade']);
    }

    #[Test]
    public function it_returns_is_sfc_false_when_no_closing_php_tag(): void
    {
        $content = '<?php'."\n".'echo "hello";';

        $result = Parser::parseSfc($content);

        $this->assertFalse($result['isSfc']);
        $this->assertSame($content, $result['php']);
        $this->assertSame('', $result['blade']);
    }

    #[Test]
    public function it_preserves_whitespace_between_php_and_blade_sections(): void
    {
        $content = "<?php\n// code\n?>\n\n<div></div>";

        $result = Parser::parseSfc($content);

        $this->assertSame("\n\n<div></div>", $result['blade']);
    }

    #[Test]
    public function it_handles_php_block_with_no_content_after_closing_tag(): void
    {
        $content = "<?php\n// code\n?>";

        $result = Parser::parseSfc($content);

        $this->assertTrue($result['isSfc']);
        $this->assertSame('', $result['blade']);
    }

    #[Test]
    public function it_reassembles_php_and_blade_sections(): void
    {
        $php = "<?php\n// code\n?>";
        $blade = "\n<div>Hello</div>";

        $this->assertSame("<?php\n// code\n?>\n<div>Hello</div>", Parser::assembleSfc($php, $blade));
    }

    #[Test]
    public function it_roundtrips_through_parse_and_assemble(): void
    {
        $content = "<?php\n\nuse App\\Models\\User;\n?>\n<div>\n    <h1>Hello</h1>\n</div>";

        $result = Parser::parseSfc($content);

        $this->assertSame($content, Parser::assembleSfc($result['php'], $result['blade']));
    }
}
