<?php

namespace App\Concerns;

use App\Rules\ValidSenderEntry;
use Illuminate\Validation\Rule;

/**
 * A mailbox's spam settings, shared by the create and update requests: the sensitivity, and the
 * allow and block lists, each given as a list or (from the panel's form) as text with one entry
 * per line or separated by commas. Entries are trimmed, lower-cased and de-duplicated.
 */
trait ValidatesSpamSettings
{
    public const array SPAM_SENSITIVITIES = ['off', 'low', 'medium', 'high'];

    public const int MAX_SPAM_LIST_ENTRIES = 100;

    protected function normalizeSpamLists(): void
    {
        foreach (['spam_allow', 'spam_block'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if ($value === null) {
                $this->merge([$field => []]);

                continue;
            }

            if (is_string($value)) {
                $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }

            if (is_array($value)) {
                $value = array_values(array_unique(array_map(
                    fn (mixed $entry): mixed => is_string($entry) ? mb_strtolower(trim($entry)) : $entry,
                    array_filter($value, fn (mixed $entry): bool => ! is_string($entry) || trim($entry) !== ''),
                ), SORT_REGULAR));
            }

            $this->merge([$field => $value]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function spamRules(): array
    {
        return [
            'spam_sensitivity' => ['sometimes', 'string', Rule::in(self::SPAM_SENSITIVITIES)],
            'spam_allow' => ['sometimes', 'array', 'max:'.self::MAX_SPAM_LIST_ENTRIES],
            'spam_allow.*' => [new ValidSenderEntry],
            'spam_block' => ['sometimes', 'array', 'max:'.self::MAX_SPAM_LIST_ENTRIES],
            'spam_block.*' => [new ValidSenderEntry],
        ];
    }
}
