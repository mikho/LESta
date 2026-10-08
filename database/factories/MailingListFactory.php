<?php

namespace Database\Factories;

use App\Models\MailDomain;
use App\Models\MailingList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailingList>
 */
class MailingListFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mail_domain_id' => MailDomain::factory(),
            'local_part' => 'list'.fake()->unique()->numerify('####'),
            'owner_email' => 'owner@example.org',
            'post_policy' => 'members',
            'subject_prefix' => null,
            'reply_to_list' => false,
        ];
    }
}
