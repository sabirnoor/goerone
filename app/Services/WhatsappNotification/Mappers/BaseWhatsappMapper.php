<?php

namespace App\Services\WhatsappNotification\Mappers;

use App\Services\WhatsappNotification\Contracts\WhatsappVarMapper;

/**
 * Base class for template mappers. A new template only needs
 * templateName() and map(); everything else has sensible defaults.
 */
abstract class BaseWhatsappMapper implements WhatsappVarMapper
{
    public static function langCode(): string
    {
        return 'en';
    }

    public static function headerFormat(): ?string
    {
        return null;
    }

    public static function needsPdfHeader(): bool
    {
        return false;
    }

    public static function staticHeaderValue(): ?string
    {
        return null;
    }

    /**
     * Make a value safe for a WhatsApp template parameter.
     * Meta rejects empty params, newlines, tabs and 4+ consecutive spaces,
     * so whitespace is collapsed and empty values get a fallback.
     */
    protected static function param(mixed $value, string $fallback = 'N/A'): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? $fallback : $text;
    }

    /** body('A', 'B') → ['body_1' => 'A', 'body_2' => 'B'] */
    protected static function body(string ...$values): array
    {
        $out = [];
        foreach (array_values($values) as $i => $value) {
            $out['body_' . ($i + 1)] = $value;
        }
        return $out;
    }

    /** URL-button suffix: button(0, 'abc') → ['btn_0_1' => 'abc'] */
    protected static function button(int $index, string $value, int $param = 1): array
    {
        return ["btn_{$index}_{$param}" => $value];
    }
}