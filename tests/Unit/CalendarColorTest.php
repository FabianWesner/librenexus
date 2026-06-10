<?php

use App\Enums\CalendarColor;

/**
 * The calendar palette is a documented constraint (Epic 04): exactly eight
 * colors, each readable with white text at WCAG AA (QG-A11Y).
 */
function calendarColorRelativeLuminance(string $hex): float
{
    $channels = sscanf($hex, '#%02x%02x%02x');

    $linear = array_map(function (int $channel): float {
        $srgb = $channel / 255;

        return $srgb <= 0.03928 ? $srgb / 12.92 : (($srgb + 0.055) / 1.055) ** 2.4;
    }, $channels);

    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}

test('the palette has exactly eight documented colors', function () {
    expect(CalendarColor::cases())->toHaveCount(8);
});

test('every palette color has a label and a valid hex value', function () {
    foreach (CalendarColor::cases() as $color) {
        expect($color->hex())->toMatch('/^#[0-9a-f]{6}$/')
            ->and($color->label())->toBe(ucfirst($color->value));
    }
});

test('every palette color passes WCAG AA contrast against white text', function () {
    foreach (CalendarColor::cases() as $color) {
        $contrast = 1.05 / (calendarColorRelativeLuminance($color->hex()) + 0.05);

        expect($contrast)->toBeGreaterThanOrEqual(
            4.5,
            "Color [{$color->value}] ({$color->hex()}) fails AA against white text (contrast {$contrast})."
        );
    }
});
