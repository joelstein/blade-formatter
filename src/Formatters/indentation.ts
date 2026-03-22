/** HTML void elements that never have closing tags */
const VOID_ELEMENTS = new Set([
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr',
]);

/**
 * Blade directives that open a block.
 * Closing directives and pairs are derived automatically
 * (e.g. @if → @endif, @foreach → @endforeach).
 */
const OPENING_DIRECTIVES = new Set([
    '@if', '@foreach', '@for', '@while', '@switch',
    '@section', '@push', '@pushOnce', '@prepend',
    '@component', '@slot',
    '@auth', '@guest', '@can', '@cannot', '@canany',
    '@env', '@production',
    '@unless', '@isset', '@empty', '@forelse',
    '@verbatim', '@php',
    '@once', '@persist',
    '@fragment', '@teleport',
    '@assets', '@script', '@style',
]);

/** Derived: closing directives (@end* versions of opening directives) */
const CLOSING_DIRECTIVES = new Set(
    [...OPENING_DIRECTIVES].map(d => '@end' + d.slice(1))
);

/** Derived: map of opening → closing directive pairs */
const DIRECTIVE_PAIRS = new Map(
    [...OPENING_DIRECTIVES].map(d => [d, '@end' + d.slice(1)])
);

/** Blade directives that sit at the same indent as their opening directive */
const MIDBLOCK_DIRECTIVES = new Set([
    '@else', '@elseif',
]);

/**
 * Directives that close a previous sibling block and open a new one.
 * Written at the dedented level, then re-indent for children.
 * Unlike midblock, these stay one level inside their parent.
 */
const CASE_DIRECTIVES = new Set([
    '@case', '@default',
]);

/**
 * Blocks whose content should be preserved exactly as-is (no re-indenting).
 * Maps opening pattern to closing pattern.
 */
const PRESERVE_BLOCKS: Array<{ open: RegExp; close: RegExp }> = [
    { open: /^@verbatim\b/, close: /^@endverbatim\b/ },
    { open: /^<pre[\s>]/, close: /^<\/pre\s*>/ },
];

/**
 * Apply simple auto-indentation to Blade/HTML content.
 *
 * Tracks indent level based on:
 * - HTML opening/closing tags (including Blade/Flux/Livewire components)
 * - Blade directives (@if/@endif, @foreach/@endforeach, etc.)
 * - Multi-line tag attributes
 *
 * Preserves content inside @verbatim, <pre>, <script>, and <style> blocks.
 */
export function formatIndentation(content: string, indentSize: number): string {
    const indent = ' '.repeat(indentSize);
    const lines = content.split('\n');
    const result: string[] = [];
    let level = 0;
    let inMultiLineTag = false;
    let multiLineTagIsVoid = false;
    let multiLineTagIsSelfClosing = false;
    let braceDepth = 0; // tracks {/} nesting inside multi-line tag attributes
    let inCaseBlock = false; // tracks whether we're inside a @case/@default block
    let preserveBlock: { close: RegExp } | null = null; // tracks preserved blocks

    for (let lineIndex = 0; lineIndex < lines.length; lineIndex++) {
        const rawLine = lines[lineIndex];
        const trimmed = rawLine.trim();

        // Preserve empty lines
        if (trimmed === '') {
            result.push('');
            continue;
        }

        // --- Handle preserved blocks (@verbatim, <pre>, <script>, <style>) ---
        if (preserveBlock) {
            if (preserveBlock.close.test(trimmed)) {
                // Closing line gets proper indentation
                const closingAdjust = countClosingAdjustments(trimmed);
                level = Math.max(0, level + closingAdjust);
                result.push(indent.repeat(level) + trimmed);
                preserveBlock = null;
            } else {
                // Inner content — keep original indentation
                result.push(rawLine);
            }
            continue;
        }

        // Check if this line opens a preserved block
        const preserveMatch = PRESERVE_BLOCKS.find(b => b.open.test(trimmed));
        if (preserveMatch) {
            // Write the opening line with current indentation
            const closingAdjust = countClosingAdjustments(trimmed);
            level = Math.max(0, level + closingAdjust);
            result.push(indent.repeat(level) + trimmed);
            level = Math.max(0, level + countOpeningAdjustments(trimmed));

            // If the closing tag is anywhere on the same line, don't enter preserve mode
            const closesOnSameLine = new RegExp(preserveMatch.close.source).test(trimmed) ||
                new RegExp(preserveMatch.close.source.replace('^', '')).test(trimmed);
            if (!closesOnSameLine) {
                preserveBlock = { close: preserveMatch.close };
            }
            continue;
        }

        // --- Handle lines that are continuations of a multi-line tag ---
        if (inMultiLineTag) {
            // Count brace changes for this line (for Alpine x-data, etc.)
            // Use raw counts here — inside multi-line tags we're tracking JS
            // nesting within attribute values, so we want to count braces
            // even when they appear inside HTML attribute quotes.
            const openBraces = countChar(trimmed, '{') + countChar(trimmed, '[');
            const closeBraces = countChar(trimmed, '}') + countChar(trimmed, ']');

            // Decrease depth before writing if line starts with a closing brace
            if (closeBraces > openBraces) {
                braceDepth = Math.max(0, braceDepth - (closeBraces - openBraces));
            }

            const closesTag = !braceDepth && (trimmed.endsWith('>') || trimmed.endsWith('/>'));
            const startsWithClosingBracket = /^\/?>/.test(trimmed);

            if (closesTag && startsWithClosingBracket) {
                // Line starts with > or /> — same indent as the opening tag
                result.push(indent.repeat(level) + trimmed);
            } else {
                // Attribute/brace content lines get extra indent
                result.push(indent.repeat(level + 1 + braceDepth) + trimmed);
            }

            // Increase depth after writing if line opens new braces
            if (openBraces > closeBraces) {
                braceDepth += openBraces - closeBraces;
            }

            if (closesTag) {
                multiLineTagIsSelfClosing = trimmed.endsWith('/>');
                inMultiLineTag = false;
                braceDepth = 0;

                // If it's not self-closing and not a void element, the tag
                // adds a block indent for its children
                if (!multiLineTagIsSelfClosing && !multiLineTagIsVoid) {
                    level++;
                }

                // Account for any closing tags on the same line as the tag close
                // (e.g. ">content</flux:button>" has a closing tag that cancels the open)
                const inlineCloseMatches = trimmed.matchAll(/<\/([\w:.-]+)\s*>/g);
                for (const _match of inlineCloseMatches) {
                    level = Math.max(0, level - 1);
                }
            }

            continue;
        }

        // --- Normal line processing ---

        // Look-ahead: if this is a comment line followed by a midblock directive,
        // indent the comment at the midblock level (one level up from current)
        if (/^\{\{--.*--\}\}$/.test(trimmed)) {
            const nextTrimmed = findNextNonEmptyLine(lines, lineIndex + 1);
            if (nextTrimmed && (isMidblockLine(nextTrimmed) || isCaseDirective(nextTrimmed))) {
                result.push(indent.repeat(Math.max(0, level - 1)) + trimmed);
                continue;
            }
        }

        // Track brace/bracket nesting for multi-line constructs
        // like @props([...]), @php([...]), JS blocks, etc.
        // Parentheses are excluded — they always wrap brackets in Blade
        // directives (e.g. @props([...])) and would double-count.
        // Strip Blade expression delimiters ({{ }}, {!! !!}) before counting
        // so they don't affect brace depth.
        const strippedLine = stripBladeDelimiters(trimmed);
        const openBraces = countChar(strippedLine, '{') + countChar(strippedLine, '[');
        const closeBraces = countChar(strippedLine, '}') + countChar(strippedLine, ']');

        // Decrease depth before writing if line has net closing braces
        if (braceDepth > 0 && closeBraces > openBraces) {
            braceDepth = Math.max(0, braceDepth - (closeBraces - openBraces));
        }

        // Handle @case/@default: close previous case block, write, open new one
        if (isCaseDirective(trimmed)) {
            // Close the previous case block if one is open
            if (inCaseBlock) {
                level = Math.max(0, level - 1);
            }
            result.push(indent.repeat(level + braceDepth) + trimmed);
            level++;
            inCaseBlock = true;

            if (openBraces > closeBraces) {
                braceDepth += openBraces - closeBraces;
            }
            continue;
        }

        // If we're closing a switch and a case block is still open, close it too
        if (inCaseBlock && /^@endswitch/.test(trimmed)) {
            level = Math.max(0, level - 1);
            inCaseBlock = false;
        }

        // @break closes the current case block (written at case content level, then dedents)
        if (inCaseBlock && /^@break/.test(trimmed)) {
            result.push(indent.repeat(level + braceDepth) + trimmed);
            level = Math.max(0, level - 1);
            inCaseBlock = false;

            if (openBraces > closeBraces) {
                braceDepth += openBraces - closeBraces;
            }
            continue;
        }

        // Calculate indent adjustments for this line
        const closingAdjust = countClosingAdjustments(trimmed);
        const midblockAdjust = isMidblockLine(trimmed) ? -1 : 0;

        // Apply closing adjustments before writing the line
        level = Math.max(0, level + closingAdjust + midblockAdjust);

        // Write the line with current indentation
        result.push(indent.repeat(level + braceDepth) + trimmed);

        // Undo midblock adjustment (midblock directives are "pass-through")
        if (midblockAdjust < 0) {
            level -= midblockAdjust;
        }

        // Increase brace depth after writing if line opens new braces
        if (openBraces > closeBraces) {
            braceDepth += openBraces - closeBraces;
        }

        // Check if this line opens a multi-line tag (has an opening tag but
        // no closing > or /> on this line)
        const multiLineMatch = trimmed.match(/^<([\w:.-]+)/);
        if (multiLineMatch && !trimmed.startsWith('</')) {
            const hasClosingBracket = /\/?>/.test(trimmed.replace(/<[\w:.-]+/, ''));
            if (!hasClosingBracket) {
                inMultiLineTag = true;
                const tagName = multiLineMatch[1].toLowerCase();
                const baseTag = tagName.split(':')[0] || tagName;
                multiLineTagIsVoid = VOID_ELEMENTS.has(tagName) || VOID_ELEMENTS.has(baseTag);
                multiLineTagIsSelfClosing = false;
                continue;
            }
        }

        // Apply opening adjustments after writing the line
        level = Math.max(0, level + countOpeningAdjustments(trimmed));
    }

    return result.join('\n');
}

function countOpeningAdjustments(line: string): number {
    let count = 0;

    // Blade opening directives
    const directiveMatch = line.match(/^@(\w+)/);
    if (directiveMatch && OPENING_DIRECTIVES.has('@' + directiveMatch[1])) {
        // Check if the closing counterpart is on the same line (e.g. @php ... @endphp)
        const closing = DIRECTIVE_PAIRS.get('@' + directiveMatch[1]);
        if (!closing || !line.includes(closing)) {
            count++;
        }
    }

    // HTML/component opening tags (not self-closing, not void elements)
    // Matches: <div, <flux:modal, <x-button, <livewire:counter
    // The attribute pattern handles > inside quoted values (e.g. :attr="fn()->method()")
    const openTagMatches = line.matchAll(/<([\w:.-]+)(?:\s(?:[^>"']|"[^"]*"|'[^']*')*)?\s*(?<!\/)\s*>/g);
    for (const match of openTagMatches) {
        const tagName = match[1].toLowerCase();
        const baseTag = tagName.split(':')[0] || tagName;

        // Skip void elements
        if (VOID_ELEMENTS.has(tagName) || VOID_ELEMENTS.has(baseTag)) {
            continue;
        }

        // Check the full match isn't self-closing
        if (!match[0].endsWith('/>')) {
            count++;
        }
    }

    // If line also contains closing tags for the same elements, cancel out
    const closeTagMatches = line.matchAll(/<\/([\w:.-]+)\s*>/g);
    for (const _match of closeTagMatches) {
        count--;
    }

    return Math.max(0, count);
}

/**
 * Find the next non-empty line starting from the given index.
 */
function findNextNonEmptyLine(lines: string[], startIndex: number): string | null {
    for (let i = startIndex; i < lines.length; i++) {
        const trimmed = lines[i].trim();
        if (trimmed !== '') {
            return trimmed;
        }
    }
    return null;
}

function countClosingAdjustments(line: string): number {
    let count = 0;

    // Blade closing directives
    const directiveMatch = line.match(/^@(\w+)/);
    if (directiveMatch && CLOSING_DIRECTIVES.has('@' + directiveMatch[1])) {
        count--;
    }

    // HTML/component closing tags at the start of the line
    if (/^<\/[\w:.-]+\s*>/.test(line)) {
        count--;
    }

    return count;
}

function isCaseDirective(line: string): boolean {
    const match = line.match(/^@(\w+)/);
    return match !== null && CASE_DIRECTIVES.has('@' + match[1]);
}

function isMidblockLine(line: string): boolean {
    const directiveMatch = line.match(/^@(\w+)/);
    if (directiveMatch && MIDBLOCK_DIRECTIVES.has('@' + directiveMatch[1])) {
        return true;
    }
    return false;
}

/**
 * Strip Blade expression delimiters so their braces don't affect depth counting.
 * Removes {{ }}, {{{ }}}, and {!! !!} delimiters but leaves content intact.
 */
function stripBladeDelimiters(line: string): string {
    return line
        .replace(/\{\{\{/g, '   ')
        .replace(/\}\}\}/g, '   ')
        .replace(/\{\{--.*?--\}\}/g, (m) => ' '.repeat(m.length))
        .replace(/\{!!/g, '   ')
        .replace(/!!\}/g, '   ')
        .replace(/\{\{/g, '  ')
        .replace(/\}\}/g, '  ');
}

/**
 * Count all occurrences of a character in a string.
 */
function countChar(line: string, char: string): number {
    let count = 0;
    for (const c of line) {
        if (c === char) {
            count++;
        }
    }
    return count;
}

