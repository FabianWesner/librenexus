<?php

namespace App\Enums;

/**
 * The documented calendar palette for staff and services (Epic 04). Each
 * entry maps to a Tailwind hex that keeps at least a 4.5:1 (WCAG AA)
 * contrast ratio against white text; hues whose 600 shade falls short of
 * AA use the 700 shade instead (QG-A11Y).
 */
enum CalendarColor: string
{
    case Indigo = 'indigo';
    case Emerald = 'emerald';
    case Amber = 'amber';
    case Rose = 'rose';
    case Sky = 'sky';
    case Violet = 'violet';
    case Teal = 'teal';
    case Orange = 'orange';

    /**
     * Get the hex value used to render this color in calendars and chips.
     */
    public function hex(): string
    {
        return match ($this) {
            self::Indigo => '#4f46e5',
            self::Emerald => '#047857',
            self::Amber => '#b45309',
            self::Rose => '#e11d48',
            self::Sky => '#0369a1',
            self::Violet => '#7c3aed',
            self::Teal => '#0f766e',
            self::Orange => '#c2410c',
        };
    }

    /**
     * Get the display label for the color.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
