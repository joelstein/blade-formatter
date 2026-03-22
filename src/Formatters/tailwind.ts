import * as path from 'path';
import * as fs from 'fs';
import * as os from 'os';
import { execFile } from 'child_process';

/**
 * Resolve the Rustywind binary path.
 *
 * Priority: user-configured path > bundled node_modules binary.
 */
function resolveRustywindPath(configuredPath: string): string {
    // If the user configured a custom path, use it
    if (configuredPath !== 'rustywind') {
        return configuredPath;
    }

    // Use the bundled version from the extension's node_modules
    const bundled = path.join(__dirname, '..', '..', 'node_modules', '.bin', 'rustywind');
    if (fs.existsSync(bundled)) {
        return bundled;
    }

    // Fall back to global
    return 'rustywind';
}

/**
 * Sort Tailwind CSS classes in Blade/HTML content using Rustywind.
 *
 * Handles both standard class="..." attributes (via Rustywind)
 * and Blade @class([...]) directives (custom sorting).
 */
export async function sortTailwindClasses(
    content: string,
    rustywindPath: string
): Promise<string> {
    rustywindPath = resolveRustywindPath(rustywindPath);

    // Step 1: Run Rustywind on the content for standard class="..." attributes
    let result = await runRustywindOnContent(rustywindPath, content);

    // Step 2: Sort classes inside @class([...]) directives
    result = await sortAtClassDirectives(rustywindPath, result);

    return result;
}

/**
 * Run Rustywind on content via a temp file.
 */
async function runRustywindOnContent(rustywindPath: string, content: string): Promise<string> {
    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'lwsfc-tw-'));
    const tmpFile = path.join(tmpDir, 'template.html');

    try {
        fs.writeFileSync(tmpFile, content, 'utf-8');

        await runRustywind(rustywindPath, tmpFile);

        return fs.readFileSync(tmpFile, 'utf-8');
    } finally {
        try {
            fs.unlinkSync(tmpFile);
            fs.rmdirSync(tmpDir);
        } catch {
            // Ignore cleanup errors
        }
    }
}

/**
 * Find and sort classes within @class([...]) directives.
 *
 * Collects all class strings, sorts them in a single Rustywind pass,
 * then replaces the originals.
 */
async function sortAtClassDirectives(rustywindPath: string, content: string): Promise<string> {
    // Find all @class([...]) blocks, including multi-line
    const classDirectiveRegex = /@class\(\[[\s\S]*?\]\)/g;
    const matches = [...content.matchAll(classDirectiveRegex)];

    if (matches.length === 0) {
        return content;
    }

    // Extract all class strings from all @class directives
    // Matches: 'classes here' or "classes here" (the key part of array entries)
    const classStringRegex = /(['"])((?:(?!\1).)*)\1(?:\s*=>)?/g;
    const classStrings: string[] = [];

    for (const match of matches) {
        const block = match[0];
        let stringMatch: RegExpExecArray | null;
        const regex = new RegExp(classStringRegex.source, classStringRegex.flags);

        while ((stringMatch = regex.exec(block)) !== null) {
            classStrings.push(stringMatch[2]);
        }
    }

    if (classStrings.length === 0) {
        return content;
    }

    // Build a temp file with each class string as a class="..." attribute
    // so Rustywind can sort them all in one pass
    const lines = classStrings.map((cs, i) => `<div id="cs${i}" class="${cs}"></div>`);
    const sortedLines = await runRustywindOnContent(rustywindPath, lines.join('\n'));

    // Extract the sorted classes back out
    const sortedClasses: string[] = [];
    const extractRegex = /class="([^"]*)"/g;
    let extractMatch: RegExpExecArray | null;
    while ((extractMatch = extractRegex.exec(sortedLines)) !== null) {
        sortedClasses.push(extractMatch[1]);
    }

    if (sortedClasses.length !== classStrings.length) {
        // Something went wrong — return content unchanged
        return content;
    }

    // Build a map of original → sorted class strings
    const sortMap = new Map<string, string>();
    for (let i = 0; i < classStrings.length; i++) {
        sortMap.set(classStrings[i], sortedClasses[i]);
    }

    // Replace each class string in @class directives with its sorted version
    let result = content;
    result = result.replace(classDirectiveRegex, (block) => {
        const regex = new RegExp(classStringRegex.source, classStringRegex.flags);
        return block.replace(regex, (fullMatch, quote, classValue) => {
            const sorted = sortMap.get(classValue);
            if (sorted === undefined || sorted === classValue) {
                return fullMatch;
            }
            return fullMatch.replace(`${quote}${classValue}${quote}`, `${quote}${sorted}${quote}`);
        });
    });

    return result;
}

function runRustywind(rustywindPath: string, filePath: string): Promise<void> {
    return new Promise((resolve, reject) => {
        execFile(
            rustywindPath,
            ['--write', filePath],
            { timeout: 10000 },
            (error, _stdout, stderr) => {
                if (error) {
                    reject(new Error(`Rustywind failed: ${stderr || error.message}`));
                    return;
                }
                resolve();
            }
        );
    });
}
