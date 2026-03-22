export interface SfcParts {
    /** The PHP section including <?php and ?> tags */
    php: string;
    /** Everything after the closing ?> tag */
    blade: string;
    /** Whether this file looks like a Livewire SFC */
    isSfc: boolean;
}

/**
 * Parse a Livewire SFC into its PHP and Blade sections.
 *
 * Livewire SFCs have the structure:
 *   <?php
 *   // PHP code
 *   ?>
 *   <div>
 *       <!-- Blade template -->
 *   </div>
 */
export function parseSfc(content: string): SfcParts {
    const phpOpenIndex = content.indexOf('<?php');
    if (phpOpenIndex === -1) {
        return { php: '', blade: content, isSfc: false };
    }

    // Find the last ?> — Livewire SFCs have one PHP block at the top
    const phpCloseIndex = content.indexOf('?>', phpOpenIndex);
    if (phpCloseIndex === -1) {
        return { php: content, blade: '', isSfc: false };
    }

    const php = content.substring(phpOpenIndex, phpCloseIndex + 2);
    const blade = content.substring(phpCloseIndex + 2);

    return { php, blade, isSfc: true };
}

/**
 * Reassemble a formatted SFC from its parts.
 */
export function assembleSfc(php: string, blade: string): string {
    return php + blade;
}
