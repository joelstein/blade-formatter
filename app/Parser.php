<?php

namespace BladeFormatter;

class Parser
{
    /**
     * Parse a Livewire SFC into its PHP and Blade sections.
     *
     * @return array{php: string, blade: string, isSfc: bool}
     */
    public static function parseSfc(string $content): array
    {
        $phpOpenIndex = strpos($content, '<?php');

        if ($phpOpenIndex === false) {
            return ['php' => '', 'blade' => $content, 'isSfc' => false];
        }

        // Only treat as SFC if <?php is at the start of the file (ignoring whitespace).
        // Files with content before <?php (like @props) use inline PHP, not SFC sections.
        if (trim(substr($content, 0, $phpOpenIndex)) !== '') {
            return ['php' => '', 'blade' => $content, 'isSfc' => false];
        }

        $phpCloseIndex = strpos($content, '?>', $phpOpenIndex);

        if ($phpCloseIndex === false) {
            return ['php' => $content, 'blade' => '', 'isSfc' => false];
        }

        $php = substr($content, $phpOpenIndex, $phpCloseIndex + 2 - $phpOpenIndex);
        $blade = substr($content, $phpCloseIndex + 2);

        return ['php' => $php, 'blade' => $blade, 'isSfc' => true];
    }

    /**
     * Reassemble a formatted SFC from its parts.
     */
    public static function assembleSfc(string $php, string $blade): string
    {
        return $php.$blade;
    }
}
