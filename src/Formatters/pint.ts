import * as path from 'path';
import * as fs from 'fs';
import * as os from 'os';
import { execFile } from 'child_process';

/**
 * Format PHP code using Laravel Pint.
 *
 * Writes the PHP section to a temp file, runs Pint on it,
 * and returns the formatted result.
 */
export async function formatWithPint(
    phpCode: string,
    workspaceRoot: string,
    pintPath: string
): Promise<string> {
    const resolvedPintPath = path.isAbsolute(pintPath)
        ? pintPath
        : path.join(workspaceRoot, pintPath);

    if (!fs.existsSync(resolvedPintPath)) {
        throw new Error(`Pint not found at: ${resolvedPintPath}`);
    }

    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'lwsfc-'));
    const tmpFile = path.join(tmpDir, 'component.php');

    try {
        fs.writeFileSync(tmpFile, phpCode, 'utf-8');

        await runPint(resolvedPintPath, tmpFile, workspaceRoot);

        let formatted = fs.readFileSync(tmpFile, 'utf-8').trimEnd();

        // Pint strips closing ?> (PSR-12), but SFCs need it
        if (!formatted.endsWith('?>')) {
            formatted += '\n?>';
        }

        return formatted;
    } finally {
        // Clean up temp files
        try {
            fs.unlinkSync(tmpFile);
            fs.rmdirSync(tmpDir);
        } catch {
            // Ignore cleanup errors
        }
    }
}

function runPint(pintPath: string, filePath: string, cwd: string): Promise<void> {
    return new Promise((resolve, reject) => {
        execFile(
            'php',
            [pintPath, filePath, '--quiet'],
            { cwd, timeout: 15000 },
            (error, _stdout, stderr) => {
                // Pint exits with 1 when it makes changes, which is fine
                if (error && error.code !== 1) {
                    reject(new Error(`Pint failed: ${stderr || error.message}`));
                    return;
                }
                resolve();
            }
        );
    });
}
