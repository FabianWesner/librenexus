<?php

namespace App\Rules;

use Closure;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a tenant URL slug (FR-TENANT-8, ARCH-ROUTING-4). Extends TeamName
 * so the reserved-name list stays single-source: a slug must never shadow a
 * static route or reserved path. Uniqueness is checked separately with
 * Rule::unique so the current team can be ignored on update.
 */
class TeamSlug extends TeamName
{
    private const string FORMAT_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    private const int MAX_LENGTH = 64;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::FORMAT_PATTERN, $value) !== 1) {
            $fail(__('The slug may only contain lowercase letters, numbers, and single hyphens between them.'));

            return;
        }

        if (strlen($value) > self::MAX_LENGTH) {
            $fail(__('The slug may not be longer than :max characters.', ['max' => self::MAX_LENGTH]));

            return;
        }

        if (in_array($value, $this->reservedNames(), true)) {
            $fail(__('This slug is reserved and cannot be used.'));
        }
    }
}
