import * as vscode from 'vscode';
import { parseSfc, assembleSfc } from './parser';
import { formatWithPint } from './formatters/pint';
import { sortTailwindClasses } from './formatters/tailwind';
import { formatIndentation } from './formatters/indentation';

/** Track which warnings have been shown so we don't spam the user */
const shownWarnings = new Set<string>();

function warnOnce(key: string, message: string): void {
    if (!shownWarnings.has(key)) {
        shownWarnings.add(key);
        vscode.window.showWarningMessage(message);
    }
}

export interface FormatOptions {
    enablePint: boolean;
    enableTailwindSort: boolean;
    enableIndentation: boolean;
    pintPath: string;
    rustywindPath: string;
    indentSize: number;
    workspaceRoot: string;
}

/**
 * Format a Blade document.
 *
 * For SFCs (with <?php ... ?>):
 * 1. Parse into PHP + Blade sections
 * 2. Run Pint on PHP section
 * 3. Sort Tailwind classes in Blade section
 * 4. Auto-indent Blade section
 * 5. Reassemble
 *
 * For regular Blade files:
 * 1. Sort Tailwind classes
 * 2. Auto-indent
 */
export async function formatDocument(
    content: string,
    options: FormatOptions,
    outputChannel: vscode.OutputChannel
): Promise<string> {
    const { php, blade, isSfc } = parseSfc(content);

    let formattedPhp = php;
    let formattedBlade = isSfc ? blade : content;

    // Step 1: Format PHP with Pint (SFCs only)
    if (options.enablePint && isSfc) {
        try {
            formattedPhp = await formatWithPint(formattedPhp, options.workspaceRoot, options.pintPath);
            outputChannel.appendLine('Pint formatting applied.');
        } catch (err) {
            const message = err instanceof Error ? err.message : String(err);
            outputChannel.appendLine(`Pint formatting skipped: ${message}`);
            warnOnce('pint', `Livewire SFC Formatter: Pint skipped — ${message}`);
        }
    }

    // Step 2: Sort Tailwind classes
    if (options.enableTailwindSort) {
        try {
            formattedBlade = await sortTailwindClasses(formattedBlade, options.rustywindPath);
            outputChannel.appendLine('Tailwind class sorting applied.');
        } catch (err) {
            const message = err instanceof Error ? err.message : String(err);
            outputChannel.appendLine(`Tailwind sorting skipped: ${message}`);
            warnOnce('rustywind', `Livewire SFC Formatter: Rustywind skipped — ${message}`);
        }
    }

    // Step 3: Auto-indent Blade
    if (options.enableIndentation) {
        formattedBlade = formatIndentation(formattedBlade, options.indentSize);
        outputChannel.appendLine('Blade indentation applied.');
    }

    let result = isSfc
        ? assembleSfc(formattedPhp, formattedBlade)
        : formattedBlade;

    // Step 4: Collapse multiple consecutive blank lines into one
    result = result.replace(/\n{3,}/g, '\n\n');

    return result;
}
