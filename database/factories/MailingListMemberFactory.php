<?php

namespace Database\Factories;

use App\Models\MailingList;
use App\Models\MailingListMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailingListMember>
 */
class MailingListMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mailing_list_id' => MailingList::factory(),
            'email' => 'member'.fake()->unique()->numerify('#####').'@example.org',
        ];
    }
}
