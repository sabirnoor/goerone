<?php

namespace App\Http\Helper;

/**
 * Builds the WhatsApp Cloud API template message payload.
 *
 * var_values format (same keys you already store in JSON):
 *   body_1, body_2, body_3 …  → body component parameters
 *   btn_0_1, btn_0_2 …        → button index 0, parameter 1
 *   btn_1_1, btn_1_2 …        → button index 1, parameter 1
 *
 * Example var_values:
 *   [
 *     'body_1'  => 'John',
 *     'body_2'  => 'Order #123',
 *     'btn_0_1' => 'https://track.example.com/123',
 *   ]
 */
class CampaignPayloadBuilder
{
    /**
     * Build the full API payload for a single recipient.
     *
     * @param  string  $phone         E.164 phone number (digits only, no +)
     * @param  string  $templateName  WhatsApp template name
     * @param  array   $varValues     Variable map (body_N, btn_N_M keys)
     * @param  string  $langCode      Language code (default: en)
     * @param  string|null $headerFormat  Header type: TEXT | IMAGE | DOCUMENT | VIDEO (or null)
     * @param  string|null $headerValue   For TEXT: the text string. For media: the media URL or handle.
     * @return array
     */
    public static function build(
        string $phone,
        string $templateName,
        array  $varValues,
        string $langCode = 'en',
        ?string $headerFormat   = null,
        ?string $headerValue    = null,
        ?string $headerFilename = null,
    ): array {
        $components = [];

        // ── Header component ──────────────────────────────────────────────────
        $headerComponent = self::buildHeaderComponent($headerFormat, $headerValue, $headerFilename);
        if ($headerComponent !== null) {
            $components[] = $headerComponent;
        }

        // ── Body parameters ───────────────────────────────────────────────────
        $bodyParams = self::extractBodyParams($varValues);
        if (!empty($bodyParams)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => $bodyParams,
            ];
        }

        // ── Button parameters ─────────────────────────────────────────────────
        $buttonComponents = self::extractButtonComponents($varValues);
        foreach ($buttonComponents as $component) {
            $components[] = $component;
        }

        return [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => $langCode],
                'components' => $components,
            ],
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build the header component for the API payload.
     *
     * TEXT headers have no dynamic parameters (the text is baked into the template).
     * IMAGE / DOCUMENT / VIDEO headers require a link or handle parameter.
     *
     * Returns null when there is nothing to send (no header, or TEXT with no variable).
     */
    private static function buildHeaderComponent(?string $format, ?string $value, ?string $filename = null): ?array
    {
        if (empty($format)) {
            return null;
        }

        $format = strtoupper($format);

        // TEXT headers: only add a component if a variable value exists ({{1}} in header text)
        if ($format === 'TEXT') {
            if (empty($value)) {
                return null;
            }
            return [
                'type'       => 'header',
                'parameters' => [
                    ['type' => 'text', 'text' => $value],
                ],
            ];
        }

        // Media headers (IMAGE, DOCUMENT, VIDEO)
        if (in_array($format, ['IMAGE', 'DOCUMENT', 'VIDEO'], true)) {
            if (empty($value)) {
                return null;
            }

            $mediaType = strtolower($format); // 'image' | 'document' | 'video'

            $mediaObject = ['link' => $value];

            // Documents can carry a display filename shown on the recipient's phone
            if ($format === 'DOCUMENT' && !empty($filename)) {
                $mediaObject['filename'] = $filename;
            }

            return [
                'type'       => 'header',
                'parameters' => [
                    [
                        'type'     => $mediaType,
                        $mediaType => $mediaObject,
                    ],
                ],
            ];
        }

        return null;
    }

    /**
     * Extract body_1, body_2 … keys into ordered parameter objects.
     */
    private static function extractBodyParams(array $varValues): array
    {
        $params = [];
        $i = 1;

        while (isset($varValues["body_{$i}"])) {
            $params[] = [
                'type' => 'text',
                'text' => (string) $varValues["body_{$i}"],
            ];
            $i++;
        }

        return $params;
    }

    /**
     * Extract btn_{buttonIndex}_{paramIndex} keys into button components.
     *
     * Supports URL buttons (dynamic suffix).
     * To extend for other button types, add more sub_type logic here.
     */
    private static function extractButtonComponents(array $varValues): array
    {
        // Group keys: btn_0_1, btn_0_2 → index 0; btn_1_1 → index 1 …
        $grouped = [];

        foreach ($varValues as $key => $value) {
            if (!preg_match('/^btn_(\d+)_(\d+)$/', $key, $m)) {
                continue;
            }
            $buttonIndex = (int) $m[1];
            $grouped[$buttonIndex][] = [
                'type' => 'text',
                'text' => (string) $value,
            ];
        }

        $components = [];
        foreach ($grouped as $index => $params) {
            $components[] = [
                'type'       => 'button',
                'sub_type'   => 'url',   // adjust if you need QUICK_REPLY etc.
                'index'      => $index,
                'parameters' => $params,
            ];
        }

        return $components;
    }
}
