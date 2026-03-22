import { describe, it, expect } from 'vitest';
import { sortTailwindClasses } from '../src/formatters/tailwind';
import * as path from 'path';
import * as fs from 'fs';

// Resolve the bundled rustywind binary
const rustywindPath = path.join(__dirname, '..', 'node_modules', '.bin', 'rustywind');
const hasRustywind = fs.existsSync(rustywindPath);

describe.runIf(hasRustywind)('sortTailwindClasses', () => {
    it('sorts standard class attributes', async () => {
        const input = '<div class="font-bold text-red-500 flex"></div>';
        const result = await sortTailwindClasses(input, rustywindPath);

        expect(result.trim()).toBe('<div class="flex font-bold text-red-500"></div>');
    });

    it('sorts classes inside @class directives', async () => {
        const input = `<div @class([
    'font-bold text-red-500 flex',
    'mt-4 block p-2' => $active,
])></div>`;

        const result = await sortTailwindClasses(input, rustywindPath);

        expect(result).toContain("'flex font-bold text-red-500'");
        expect(result).toContain("'block p-2 mt-4' => $active");
    });

    it('handles single-line @class directives', async () => {
        const input = `<div @class(['font-bold text-red-500 flex'])></div>`;
        const result = await sortTailwindClasses(input, rustywindPath);

        expect(result).toContain("'flex font-bold text-red-500'");
    });

    it('preserves non-class content around @class directives', async () => {
        const input = `<div
    @class(['font-bold text-red-500 flex'])
    x-data="{ open: false }"
></div>`;

        const result = await sortTailwindClasses(input, rustywindPath);

        expect(result).toContain("'flex font-bold text-red-500'");
        expect(result).toContain('x-data="{ open: false }"');
    });

    it('handles @class with double quotes', async () => {
        const input = `<div @class(["font-bold text-red-500 flex"])></div>`;
        const result = await sortTailwindClasses(input, rustywindPath);

        expect(result).toContain('"flex font-bold text-red-500"');
    });

    it('leaves already-sorted @class directives unchanged', async () => {
        const input = `<div @class(['flex font-bold text-red-500'])></div>`;
        const result = await sortTailwindClasses(input, rustywindPath);

        expect(result).toContain("'flex font-bold text-red-500'");
    });
});
