<?php

use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('an owner creates a list, adds members and deletes it from the mailing lists page', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $this->actingAs($owner);

    $page = visit(route('mail.lists.index', $mailDomain));

    $page->assertSee('Mailing lists')
        ->assertSee('No mailing lists yet.')
        ->fill('local_part', 'news')
        ->fill('owner_email', 'boss@corp.example')
        ->click('[data-test="create-list-button"]')
        ->assertSee('news@'.$mailDomain->domain)
        ->assertSee('Nobody has joined yet')
        ->fill('emails', "ann@example.com, bob@example.com\nnot-an-address")
        ->click('Add members')
        ->assertSee('is not a plain email address')
        ->fill('emails', "ann@example.com, bob@example.com\ncy@example.com")
        ->click('Add members')
        ->assertSee('ann@example.com')
        ->assertSee('3 of 500 members')
        ->click('[aria-label="Remove bob@example.com from news"]')
        ->assertDontSee('bob@example.com')
        ->click('[aria-label="Delete the list news"]')
        ->assertSee('No mailing lists yet.')
        ->assertNoJavaScriptErrors();
});
