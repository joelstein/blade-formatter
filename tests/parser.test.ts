import { describe, it, expect } from 'vitest';
import { parseSfc, assembleSfc } from '../src/parser';

describe('parseSfc', () => {
    it('parses a standard Livewire SFC', () => {
        const content = `<?php

use App\\Models\\User;

new class extends Component {
    public string $name = '';
};
?>
<div>
    <h1>Hello</h1>
</div>`;

        const result = parseSfc(content);

        expect(result.isSfc).toBe(true);
        expect(result.php).toContain('<?php');
        expect(result.php).toContain('?>');
        expect(result.php).toContain('use App\\Models\\User');
        expect(result.blade).toContain('<div>');
        expect(result.blade).toContain('<h1>Hello</h1>');
    });

    it('returns isSfc false when no PHP opening tag', () => {
        const content = `<div>
    <h1>Hello</h1>
</div>`;

        const result = parseSfc(content);

        expect(result.isSfc).toBe(false);
        expect(result.php).toBe('');
        expect(result.blade).toBe(content);
    });

    it('returns isSfc false when no closing PHP tag', () => {
        const content = `<?php
echo "hello";`;

        const result = parseSfc(content);

        expect(result.isSfc).toBe(false);
        expect(result.php).toBe(content);
        expect(result.blade).toBe('');
    });

    it('preserves whitespace between PHP and Blade sections', () => {
        const content = `<?php
// code
?>

<div></div>`;

        const result = parseSfc(content);

        expect(result.blade).toBe('\n\n<div></div>');
    });

    it('handles PHP block with no content after closing tag', () => {
        const content = `<?php
// code
?>`;

        const result = parseSfc(content);

        expect(result.isSfc).toBe(true);
        expect(result.blade).toBe('');
    });
});

describe('assembleSfc', () => {
    it('reassembles PHP and Blade sections', () => {
        const php = `<?php
// code
?>`;
        const blade = `
<div>Hello</div>`;

        expect(assembleSfc(php, blade)).toBe(`<?php
// code
?>
<div>Hello</div>`);
    });

    it('roundtrips through parse and assemble', () => {
        const content = `<?php

use App\\Models\\User;
?>
<div>
    <h1>Hello</h1>
</div>`;

        const { php, blade } = parseSfc(content);
        expect(assembleSfc(php, blade)).toBe(content);
    });
});
