<?php

namespace BladeFormatter\Formatters;

class IndentationFormatter
{
    /** HTML void elements that never have closing tags */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** Blade directives that open a block */
    private const OPENING_DIRECTIVES = [
        '@if', '@foreach', '@for', '@while', '@switch',
        '@section', '@push', '@pushOnce', '@prepend',
        '@component', '@slot',
        '@auth', '@guest', '@can', '@cannot', '@canany',
        '@env', '@production',
        '@unless', '@isset', '@empty', '@forelse',
        '@verbatim',
        '@once', '@persist', '@placeholder',
        '@fragment', '@teleport',
        '@assets', '@script', '@style',
    ];

    /** Blade directives that sit at the same indent as their opening directive */
    private const MIDBLOCK_DIRECTIVES = ['@else', '@elseif'];

    /** Directives that close a previous sibling block and open a new one */
    private const CASE_DIRECTIVES = ['@case', '@default'];

    /** Blocks whose content should be preserved exactly as-is */
    private const PRESERVE_BLOCKS = [
        ['open' => '/^@verbatim\b/', 'close' => '/^@endverbatim\b/'],
        ['open' => '/^<pre[\s>]/', 'close' => '/^<\/pre\s*>/'],
    ];

    /** Blocks whose content is re-indented to the current level but internal whitespace is preserved */
    private const INDENT_PRESERVE_BLOCKS = [
        ['open' => '/^@php\s*$/', 'close' => '/^@endphp\b/'],
    ];

    /** @var list<string> */
    private array $closingDirectives;

    /** @var array<string, string> */
    private array $directivePairs;

    public function __construct()
    {
        $this->closingDirectives = array_map(
            fn (string $d): string => '@end'.substr($d, 1),
            self::OPENING_DIRECTIVES
        );

        $this->directivePairs = [];
        foreach (self::OPENING_DIRECTIVES as $d) {
            $this->directivePairs[$d] = '@end'.substr($d, 1);
        }
    }

    public function format(string $content, int $indentSize = 4): string
    {
        $indent = str_repeat(' ', $indentSize);
        $lines = explode("\n", $content);
        $result = [];
        $level = 0;
        $inMultiLineTag = false;
        $multiLineTagIsVoid = false;
        $multiLineTagIsSelfClosing = false;
        $braceDepth = 0;
        $directiveDepth = 0;
        $tagBraceOffset = 0;
        $inCaseBlock = false;
        $preserveBlock = null;
        $indentPreserveBlock = null;
        $indentPreserveLevel = 0;

        for ($lineIndex = 0; $lineIndex < count($lines); $lineIndex++) {
            $rawLine = $lines[$lineIndex];
            $trimmed = trim($rawLine);

            // Preserve empty lines
            if ($trimmed === '') {
                $result[] = '';

                continue;
            }

            // --- Handle preserved blocks (@verbatim, <pre>) ---
            if ($preserveBlock !== null) {
                if (preg_match($preserveBlock['close'], $trimmed)) {
                    $closingAdjust = $this->countClosingAdjustments($trimmed);
                    $level = max(0, $level + $closingAdjust);
                    $result[] = str_repeat($indent, $level).$trimmed;
                    $preserveBlock = null;
                } else {
                    $result[] = $rawLine;
                }

                continue;
            }

            // --- Handle indent-preserved blocks (@php) ---
            // Content is shifted to the current indent level but internal whitespace is preserved
            if ($indentPreserveBlock !== null) {
                if (preg_match($indentPreserveBlock['close'], $trimmed)) {
                    $closingAdjust = $this->countClosingAdjustments($trimmed);
                    $level = max(0, $level + $closingAdjust);
                    $result[] = str_repeat($indent, $level).$trimmed;
                    $indentPreserveBlock = null;
                } else {
                    // Prepend base indent, preserving the line's own relative whitespace from Pint
                    $result[] = str_repeat($indent, $indentPreserveLevel).$rawLine;
                }

                continue;
            }

            // Check if this line opens a preserved block
            $preserveMatch = null;
            foreach (self::PRESERVE_BLOCKS as $block) {
                if (preg_match($block['open'], $trimmed)) {
                    $preserveMatch = $block;
                    break;
                }
            }

            if ($preserveMatch !== null) {
                $closingAdjust = $this->countClosingAdjustments($trimmed);
                $level = max(0, $level + $closingAdjust);
                $result[] = str_repeat($indent, $level).$trimmed;
                $level = max(0, $level + $this->countOpeningAdjustments($trimmed));

                // If the closing tag is on the same line, don't enter preserve mode
                $closePattern = $preserveMatch['close'];
                $closePatternInline = str_replace('^', '', substr($closePattern, 1, -1));
                $closesOnSameLine = preg_match($closePattern, $trimmed) || preg_match('/'.$closePatternInline.'/', $trimmed);

                if (! $closesOnSameLine) {
                    $preserveBlock = ['close' => $preserveMatch['close']];
                }

                continue;
            }

            // Check if this line opens an indent-preserved block
            $indentPreserveMatch = null;
            foreach (self::INDENT_PRESERVE_BLOCKS as $block) {
                if (preg_match($block['open'], $trimmed)) {
                    $indentPreserveMatch = $block;
                    break;
                }
            }

            if ($indentPreserveMatch !== null) {
                $closingAdjust = $this->countClosingAdjustments($trimmed);
                $level = max(0, $level + $closingAdjust);
                $result[] = str_repeat($indent, $level).$trimmed;
                $indentPreserveLevel = $level + 1;
                $indentPreserveBlock = ['close' => $indentPreserveMatch['close']];

                continue;
            }

            // --- Handle lines that are continuations of a multi-line tag ---
            if ($inMultiLineTag) {
                $openBraces = $this->countChar($trimmed, '{') + $this->countChar($trimmed, '[');
                $closeBraces = $this->countChar($trimmed, '}') + $this->countChar($trimmed, ']');

                $priorTagBraceOffset = $tagBraceOffset;
                if ($closeBraces > $openBraces) {
                    $braceDepth = max(0, $braceDepth - ($closeBraces - $openBraces));
                    if ($braceDepth === 0) {
                        $tagBraceOffset = 0;
                    }
                }

                // Detect Blade directives inside multi-line tags (e.g. @if/@endif for conditional attributes)
                $isDirectiveLine = (bool) preg_match('/^@(\w+)/', $trimmed, $tagDirectiveMatch);
                $tagDirective = $isDirectiveLine ? '@'.$tagDirectiveMatch[1] : null;

                if ($isDirectiveLine && in_array($tagDirective, $this->closingDirectives)) {
                    $directiveDepth = max(0, $directiveDepth - 1);
                    $result[] = str_repeat($indent, $level + 1 + $braceDepth + $directiveDepth).$trimmed;
                } elseif ($isDirectiveLine && $this->isMidblockLine($trimmed)) {
                    $result[] = str_repeat($indent, $level + 1 + $braceDepth + max(0, $directiveDepth - 1)).$trimmed;
                } else {
                    $closesTag = ! $braceDepth && (str_ends_with($trimmed, '>') || str_ends_with($trimmed, '/>'));
                    $startsWithClosingBracket = (bool) preg_match('/^\/?>/', $trimmed);
                    $closesTagWithBraces = $closesTag && ! $startsWithClosingBracket && (str_contains($trimmed, ']') || str_contains($trimmed, '}'));

                    if ($closesTag && $startsWithClosingBracket) {
                        $result[] = str_repeat($indent, $level).$trimmed;
                    } elseif ($closesTagWithBraces) {
                        $result[] = str_repeat($indent, $level + 1 + $priorTagBraceOffset).$trimmed;
                    } else {
                        $result[] = str_repeat($indent, $level + 1 + $braceDepth + $directiveDepth + $tagBraceOffset).$trimmed;
                    }
                }

                if ($isDirectiveLine && in_array($tagDirective, self::OPENING_DIRECTIVES)) {
                    $directiveDepth++;
                }

                if ($openBraces > $closeBraces) {
                    $braceDepth += $openBraces - $closeBraces;
                }

                $closesTag = ! $braceDepth && (str_ends_with($trimmed, '>') || str_ends_with($trimmed, '/>'));
                if ($closesTag && ! $isDirectiveLine) {
                    $multiLineTagIsSelfClosing = str_ends_with($trimmed, '/>');
                    $inMultiLineTag = false;
                    $braceDepth = 0;
                    $directiveDepth = 0;
                    $tagBraceOffset = 0;

                    if (! $multiLineTagIsSelfClosing && ! $multiLineTagIsVoid) {
                        $level++;
                    }

                    // Account for closing tags on the same line as the tag close
                    preg_match_all('/<\/([\w:.-]+)\s*>/', $trimmed, $inlineCloseMatches);
                    foreach ($inlineCloseMatches[0] as $_match) {
                        $level = max(0, $level - 1);
                    }
                }

                continue;
            }

            // --- Normal line processing ---

            // Look-ahead: comment before midblock directive
            if (preg_match('/^\{\{--.*--\}\}$/', $trimmed)) {
                $nextTrimmed = $this->findNextNonEmptyLine($lines, $lineIndex + 1);
                if ($nextTrimmed !== null && ($this->isMidblockLine($nextTrimmed) || $this->isCaseDirective($nextTrimmed))) {
                    $result[] = str_repeat($indent, max(0, $level - 1)).$trimmed;

                    continue;
                }
            }

            // Track brace/bracket nesting
            $strippedLine = $this->stripBladeDelimiters($trimmed);
            $openBraces = $this->countChar($strippedLine, '{') + $this->countChar($strippedLine, '[');
            $closeBraces = $this->countChar($strippedLine, '}') + $this->countChar($strippedLine, ']');

            // Lines starting with } or ] close a brace level before the line is written,
            // even if the line also opens a new brace (e.g. "} else {")
            $leadingClose = $braceDepth > 0 && (bool) preg_match('/^[}\]]/', $trimmed) ? 1 : 0;
            if ($leadingClose) {
                $braceDepth--;
            }

            if ($braceDepth > 0 && ($closeBraces - $leadingClose) > $openBraces) {
                $braceDepth = max(0, $braceDepth - (($closeBraces - $leadingClose) - $openBraces));
            }

            // Handle @case/@default
            if ($this->isCaseDirective($trimmed)) {
                if ($inCaseBlock) {
                    $level = max(0, $level - 1);
                }
                $result[] = str_repeat($indent, $level + $braceDepth).$trimmed;
                $level++;
                $inCaseBlock = true;

                if ($openBraces > $closeBraces) {
                    $braceDepth += $openBraces - $closeBraces;
                }

                continue;
            }

            // Closing a switch while case block is open
            if ($inCaseBlock && preg_match('/^@endswitch/', $trimmed)) {
                $level = max(0, $level - 1);
                $inCaseBlock = false;
            }

            // @break closes the current case block
            if ($inCaseBlock && preg_match('/^@break/', $trimmed)) {
                $result[] = str_repeat($indent, $level + $braceDepth).$trimmed;
                $level = max(0, $level - 1);
                $inCaseBlock = false;

                if ($openBraces > $closeBraces) {
                    $braceDepth += $openBraces - $closeBraces;
                }

                continue;
            }

            // Calculate indent adjustments
            $closingAdjust = $this->countClosingAdjustments($trimmed);
            $midblockAdjust = $this->isMidblockLine($trimmed) ? -1 : 0;

            $level = max(0, $level + $closingAdjust + $midblockAdjust);

            // Continuation lines (starting with . operator) get an extra indent
            $continuation = $braceDepth > 0 && preg_match('/^\.(?:\s|$)/', $trimmed) ? 1 : 0;

            $result[] = str_repeat($indent, $level + $braceDepth + $continuation).$trimmed;

            // Undo midblock adjustment
            if ($midblockAdjust < 0) {
                $level -= $midblockAdjust;
            }

            // Increase brace depth after writing (account for leading close already applied)
            $remainingOpens = $openBraces - ($closeBraces - $leadingClose);
            if ($remainingOpens > 0) {
                $braceDepth += $remainingOpens;
            }

            // Check for multi-line tag opening
            if (preg_match('/^<([\w:.-]+)/', $trimmed, $multiLineMatch) && ! str_starts_with($trimmed, '</')) {
                $withoutTag = (string) preg_replace('/<[\w:.-]+/', '', $trimmed, 1);
                $hasClosingBracket = (bool) preg_match('/\/?>/', $withoutTag);

                if (! $hasClosingBracket) {
                    $inMultiLineTag = true;
                    $tagName = strtolower($multiLineMatch[1]);
                    $baseTag = explode(':', $tagName)[0] ?: $tagName;
                    $multiLineTagIsVoid = in_array($tagName, self::VOID_ELEMENTS) || in_array($baseTag, self::VOID_ELEMENTS);
                    $multiLineTagIsSelfClosing = false;

                    // When the tag-opening line itself opens braces (e.g. <div x-data="{
                    // or <div @class([), offset so content aligns at the tag level
                    if ($braceDepth > 0) {
                        $tagBraceOffset = -1;
                    }

                    continue;
                }
            }

            // Apply opening adjustments after writing
            $level = max(0, $level + $this->countOpeningAdjustments($trimmed));
        }

        return implode("\n", $result);
    }

    private function countOpeningAdjustments(string $line): int
    {
        $count = 0;

        // Blade opening directives
        if (preg_match('/^@(\w+)/', $line, $directiveMatch)) {
            $directive = '@'.$directiveMatch[1];
            if (in_array($directive, self::OPENING_DIRECTIVES)) {
                // @php(...) is an inline expression, not a block — no indent change
                if ($directiveMatch[1] === 'php' && preg_match('/^@php\s*\(/', $line)) {
                    // skip — inline @php expression
                } else {
                    $closing = $this->directivePairs[$directive] ?? null;
                    if ($closing === null || ! str_contains($line, $closing)) {
                        $count++;
                    }
                }
            }
        }

        // HTML/component opening tags (not self-closing, not void)
        preg_match_all('/<([\w:.-]+)(?:\s(?:[^>"\']*|"[^"]*"|\'[^\']*\')*)?\s*(?<!\/)\s*>/s', $line, $openTagMatches, PREG_SET_ORDER);
        foreach ($openTagMatches as $match) {
            $tagName = strtolower($match[1]);
            $baseTag = explode(':', $tagName)[0] ?: $tagName;

            if (in_array($tagName, self::VOID_ELEMENTS) || in_array($baseTag, self::VOID_ELEMENTS)) {
                continue;
            }

            if (! str_ends_with($match[0], '/>')) {
                $count++;
            }
        }

        // Cancel out closing tags on the same line
        preg_match_all('/<\/([\w:.-]+)\s*>/', $line, $closeTagMatches);
        $count -= count($closeTagMatches[0]);

        return max(0, $count);
    }

    private function countClosingAdjustments(string $line): int
    {
        $count = 0;

        // Blade closing directives
        if (preg_match('/^@(\w+)/', $line, $directiveMatch)) {
            $directive = '@'.$directiveMatch[1];
            if (in_array($directive, $this->closingDirectives)) {
                $count--;
            }
        }

        // HTML/component closing tags at the start
        if (preg_match('/^<\/[\w:.-]+\s*>/', $line)) {
            $count--;
        }

        return $count;
    }

    private function isCaseDirective(string $line): bool
    {
        if (preg_match('/^@(\w+)/', $line, $match)) {
            return in_array('@'.$match[1], self::CASE_DIRECTIVES);
        }

        return false;
    }

    private function isMidblockLine(string $line): bool
    {
        if (preg_match('/^@(\w+)/', $line, $match)) {
            return in_array('@'.$match[1], self::MIDBLOCK_DIRECTIVES);
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     */
    private function findNextNonEmptyLine(array $lines, int $startIndex): ?string
    {
        for ($i = $startIndex; $i < count($lines); $i++) {
            $trimmed = trim($lines[$i]);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Strip Blade expression delimiters and quoted string contents
     * so their braces don't affect depth counting.
     */
    private function stripBladeDelimiters(string $line): string
    {
        $line = str_replace('{{{', '   ', $line);
        $line = str_replace('}}}', '   ', $line);
        $line = (string) preg_replace_callback('/\{\{--.*?--\}\}/', fn (array $m): string => str_repeat(' ', strlen($m[0])), $line);
        $line = str_replace('{!!', '   ', $line);
        $line = str_replace('!!}', '   ', $line);
        $line = str_replace('{{', '  ', $line);
        $line = str_replace('}}', '  ', $line);

        // Strip content inside quoted strings so braces in string literals
        // (e.g. ICU format strings like '{count, plural, one {# hour}}')
        // don't affect brace depth counting
        $line = (string) preg_replace_callback(
            '/([\'"])(?:(?!\1).)*\1/',
            fn (array $m): string => $m[1].str_repeat(' ', max(0, strlen($m[0]) - 2)).$m[1],
            $line,
        );

        return $line;
    }

    private function countChar(string $line, string $char): int
    {
        return substr_count($line, $char);
    }
}
