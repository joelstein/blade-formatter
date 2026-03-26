"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || (function () {
    var ownKeys = function(o) {
        ownKeys = Object.getOwnPropertyNames || function (o) {
            var ar = [];
            for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) ar[ar.length] = k;
            return ar;
        };
        return ownKeys(o);
    };
    return function (mod) {
        if (mod && mod.__esModule) return mod;
        var result = {};
        if (mod != null) for (var k = ownKeys(mod), i = 0; i < k.length; i++) if (k[i] !== "default") __createBinding(result, mod, k[i]);
        __setModuleDefault(result, mod);
        return result;
    };
})();
Object.defineProperty(exports, "__esModule", { value: true });
exports.activate = activate;
exports.deactivate = deactivate;
const vscode = __importStar(require("vscode"));
const path = __importStar(require("path"));
const child_process_1 = require("child_process");
const fs = __importStar(require("fs"));
const os = __importStar(require("os"));
let outputChannel;
/** Cache PHP block positions per document, keyed by URI */
const blockCache = new Map();
/** Track documents currently being formatted to prevent double formatting */
const formatting = new Set();
function isBladeFile(document) {
    return document.fileName.endsWith('.blade.php');
}
/**
 * Find all <?php ... ?> blocks in the document text.
 * Results are cached per document version.
 */
function getPhpBlocks(document) {
    const key = document.uri.toString();
    const cached = blockCache.get(key);
    if (cached && cached.version === document.version) {
        return cached.blocks;
    }
    const blocks = [];
    const text = document.getText();
    // Match <?php ... ?> blocks (SFC sections)
    const sfcRegex = /<\?php[\s\S]*?\?>/g;
    let match;
    while ((match = sfcRegex.exec(text)) !== null) {
        blocks.push({ start: match.index, end: match.index + match[0].length });
    }
    // Match multiline @php ... @endphp blocks
    const phpBlockRegex = /@php\s*\n[\s\S]*?@endphp/g;
    while ((match = phpBlockRegex.exec(text)) !== null) {
        blocks.push({ start: match.index, end: match.index + match[0].length });
    }
    // Sort by position for consistent lookups
    blocks.sort((a, b) => a.start - b.start);
    blockCache.set(key, { version: document.version, blocks });
    return blocks;
}
/**
 * Determine which language should be active for a given offset.
 */
function languageForOffset(blocks, offset) {
    for (const block of blocks) {
        if (offset >= block.start && offset <= block.end) {
            return 'php';
        }
    }
    return 'blade';
}
/**
 * Switch the document language based on cursor position.
 */
async function switchLanguage(editor) {
    const document = editor.document;
    if (!isBladeFile(document)) {
        return;
    }
    const blocks = getPhpBlocks(document);
    if (blocks.length === 0) {
        return;
    }
    const offset = document.offsetAt(editor.selection.active);
    const desired = languageForOffset(blocks, offset);
    if (document.languageId !== desired) {
        try {
            await vscode.languages.setTextDocumentLanguage(document, desired);
        }
        catch {
            // Ignore — language ID might not be available
        }
    }
}
/**
 * Switch the document language based on visible ranges (scroll position).
 * If only one language is visible, switch to it.
 * If both are visible, fall back to cursor position.
 */
async function switchLanguageOnScroll(editor) {
    const document = editor.document;
    if (!isBladeFile(document)) {
        return;
    }
    const blocks = getPhpBlocks(document);
    if (blocks.length === 0) {
        return;
    }
    const visibleRanges = editor.visibleRanges;
    if (visibleRanges.length === 0) {
        return;
    }
    let seesPhp = false;
    let seesBlade = false;
    for (const range of visibleRanges) {
        const startOffset = document.offsetAt(range.start);
        const endOffset = document.offsetAt(range.end);
        for (const block of blocks) {
            // Check if any part of a PHP block is visible
            if (block.start <= endOffset && block.end >= startOffset) {
                seesPhp = true;
            }
        }
        // Check if any non-PHP content is visible
        // (anything outside all PHP blocks)
        let offset = startOffset;
        for (const block of blocks) {
            if (offset < block.start) {
                seesBlade = true;
                break;
            }
            offset = Math.max(offset, block.end);
        }
        if (offset < endOffset) {
            seesBlade = true;
        }
    }
    let desired;
    if (seesPhp && !seesBlade) {
        desired = 'php';
    }
    else if (seesBlade && !seesPhp) {
        desired = 'blade';
    }
    else {
        // Both visible — use cursor position
        const offset = document.offsetAt(editor.selection.active);
        desired = languageForOffset(blocks, offset);
    }
    if (document.languageId !== desired) {
        try {
            await vscode.languages.setTextDocumentLanguage(document, desired);
        }
        catch {
            // Ignore
        }
    }
}
/**
 * Resolve the blade-format binary path.
 */
function resolveBladeFormatPath(workspaceRoot) {
    const config = vscode.workspace.getConfiguration('bladeFormatter');
    const customPath = config.get('executablePath', '');
    if (customPath) {
        const resolved = path.isAbsolute(customPath)
            ? customPath
            : path.join(workspaceRoot, customPath);
        if (fs.existsSync(resolved)) {
            return resolved;
        }
    }
    // Default: vendor/bin/blade-format
    const vendorPath = path.join(workspaceRoot, 'vendor', 'bin', 'blade-format');
    if (fs.existsSync(vendorPath)) {
        return vendorPath;
    }
    return null;
}
function activate(context) {
    outputChannel = vscode.window.createOutputChannel('Blade Formatter');
    // Register as a document formatter for blade language ID
    const bladeFormatter = vscode.languages.registerDocumentFormattingEditProvider({ language: 'blade', scheme: 'file' }, { provideDocumentFormattingEdits: handleFormat });
    // Format .blade.php files on save when the language ID is "php"
    // (the blade formatter provider already handles language ID "blade")
    const saveListener = vscode.workspace.onWillSaveTextDocument((event) => {
        const config = vscode.workspace.getConfiguration('bladeFormatter');
        if (!config.get('enable', true) || !config.get('formatOnSave', true)) {
            return;
        }
        if (!isBladeFile(event.document)) {
            return;
        }
        // Skip if language is "blade" — the formatter provider handles that
        if (event.document.languageId === 'blade') {
            return;
        }
        const key = event.document.uri.toString();
        if (formatting.has(key)) {
            return;
        }
        event.waitUntil(handleFormat(event.document));
    });
    // Switch language based on cursor position
    const selectionListener = vscode.window.onDidChangeTextEditorSelection((event) => {
        const config = vscode.workspace.getConfiguration('bladeFormatter');
        if (config.get('enable', true) && config.get('enableLanguageSwitching', true)) {
            switchLanguage(event.textEditor);
        }
    });
    // Switch language based on scroll position
    const scrollListener = vscode.window.onDidChangeTextEditorVisibleRanges((event) => {
        const config = vscode.workspace.getConfiguration('bladeFormatter');
        if (config.get('enable', true) && config.get('enableLanguageSwitching', true)) {
            switchLanguageOnScroll(event.textEditor);
        }
    });
    // Switch language when opening/focusing a file
    const editorListener = vscode.window.onDidChangeActiveTextEditor((editor) => {
        const config = vscode.workspace.getConfiguration('bladeFormatter');
        if (editor && config.get('enable', true) && config.get('enableLanguageSwitching', true)) {
            switchLanguage(editor);
        }
    });
    // Clean up cache when documents close
    const closeListener = vscode.workspace.onDidCloseTextDocument((document) => {
        blockCache.delete(document.uri.toString());
    });
    // Manual command
    const command = vscode.commands.registerCommand('blade-formatter.format', async () => {
        const editor = vscode.window.activeTextEditor;
        if (!editor) {
            return;
        }
        const edits = await handleFormat(editor.document);
        if (edits && edits.length > 0) {
            const edit = new vscode.WorkspaceEdit();
            for (const e of edits) {
                edit.replace(editor.document.uri, e.range, e.newText);
            }
            await vscode.workspace.applyEdit(edit);
        }
    });
    context.subscriptions.push(bladeFormatter, saveListener, selectionListener, scrollListener, editorListener, closeListener, command, outputChannel);
    // Handle the already-active editor on startup
    if (vscode.window.activeTextEditor) {
        switchLanguage(vscode.window.activeTextEditor);
    }
    outputChannel.appendLine('Blade Formatter activated.');
}
async function handleFormat(document) {
    const config = vscode.workspace.getConfiguration('bladeFormatter');
    if (!config.get('enable', true)) {
        return [];
    }
    if (!isBladeFile(document)) {
        return [];
    }
    const key = document.uri.toString();
    if (formatting.has(key)) {
        return [];
    }
    formatting.add(key);
    try {
        return await doFormat(document);
    }
    finally {
        formatting.delete(key);
    }
}
/**
 * Format a document by writing its content to a temp file,
 * running vendor/bin/blade-format on it, and reading the result back.
 */
async function doFormat(document) {
    const workspaceFolder = vscode.workspace.getWorkspaceFolder(document.uri);
    const workspaceRoot = workspaceFolder?.uri.fsPath ?? '';
    if (!workspaceRoot) {
        vscode.window.showWarningMessage('Blade Formatter: No workspace folder found. Open a folder first.');
        return [];
    }
    const binaryPath = resolveBladeFormatPath(workspaceRoot);
    if (!binaryPath) {
        vscode.window.showWarningMessage('Blade Formatter: vendor/bin/blade-format not found. Run: composer require joelstein/blade-formatter --dev');
        return [];
    }
    const originalContent = document.getText();
    outputChannel.appendLine(`\nFormatting: ${document.fileName}`);
    try {
        // Write current content to a temp file, run blade-format on it, read back
        const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'blade-fmt-'));
        const tmpFile = path.join(tmpDir, 'format.blade.php');
        fs.writeFileSync(tmpFile, originalContent, 'utf-8');
        try {
            await runBladeFormat(binaryPath, tmpFile, workspaceRoot);
            const formatted = fs.readFileSync(tmpFile, 'utf-8');
            if (formatted === originalContent) {
                outputChannel.appendLine('No changes needed.');
                return [];
            }
            const fullRange = new vscode.Range(document.positionAt(0), document.positionAt(originalContent.length));
            outputChannel.appendLine('Formatting applied.');
            return [vscode.TextEdit.replace(fullRange, formatted)];
        }
        finally {
            try {
                fs.unlinkSync(tmpFile);
                fs.rmdirSync(tmpDir);
            }
            catch {
                // Ignore cleanup errors
            }
        }
    }
    catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        outputChannel.appendLine(`Error: ${message}`);
        vscode.window.showErrorMessage(`Blade Formatter: ${message}`);
        return [];
    }
}
function runBladeFormat(binaryPath, filePath, cwd) {
    return new Promise((resolve, reject) => {
        (0, child_process_1.execFile)('php', [binaryPath, filePath], { cwd, timeout: 30000 }, (error, _stdout, stderr) => {
            if (error) {
                reject(new Error(`blade-format failed: ${stderr || error.message}`));
                return;
            }
            resolve();
        });
    });
}
function deactivate() {
    blockCache.clear();
}
//# sourceMappingURL=extension.js.map