<?php

namespace JoelStein\BladeFormatter;

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
