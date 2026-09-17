<?php

use App\Models\Membership;
use App\Models\User;

test('a plain user only sees the user guide, with a chapter TOC, and never an admin/install link', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/docs');

    $page->assertNoJavaScriptErrors()
        ->assertSee('User Guide')
        ->assertDontSee('Admin Guide')
        ->assertDontSee('Installation Guide')
        ->click('User Guide')
        ->assertNoJavaScriptErrors()
        // The top guide switcher on a guide's own index page: only ever the user guide.
        ->assertDontSee('Admin Guide')
        ->assertDontSee('Installation Guide')
        ->assertSee('Chapter 1: Getting started')
        ->click('[data-test="chapter-link-chapter-1"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('Dashboard')
        // The right-hand chapter TOC on a chapter page itself.
        ->assertSee('In this guide')
        ->assertDontSee('Admin Guide')
        ->assertDontSee('Installation Guide')
        ->click('[data-test="toc-link-chapter-3"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('Chapter 3: Web domains');
});

test('a provider admin sees all three guides from the sidebar and can switch between them', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin);

    $page = visit('/dashboard');

    $page->assertNoJavaScriptErrors()
        ->click('Documentation')
        ->assertNoJavaScriptErrors()
        ->assertSee('User Guide')
        ->assertSee('Admin Guide')
        ->assertSee('Installation Guide')
        ->click('Admin Guide')
        ->assertNoJavaScriptErrors()
        // The top guide switcher on the Admin Guide's own index page still names all three.
        ->assertSee('User Guide')
        ->assertSee('Installation Guide')
        ->assertSee('Chapter 1: Roles overview')
        ->click('[data-test="chapter-link-chapter-1"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('Custom platform-scope roles');
});
