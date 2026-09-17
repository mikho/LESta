<?php

use App\Models\Membership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest is redirected to login', function () {
    $this->get(route('docs.index'))->assertRedirect(route('login'));
});

test('a plain user only sees the user guide in the index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('docs/index')
            ->has('guides', 1)
            ->where('guides.0.slug', 'user-guide')
        );
});

test('a provider admin sees all three guides in the index', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->get(route('docs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('docs/index')
            ->has('guides', 3)
        );
});

test('a plain user can open the user guide and its chapters, with only the user guide in the switcher', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.show', 'user-guide'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('docs/show')
            ->where('guide.slug', 'user-guide')
            ->has('guide.chapters')
            ->has('guides', 1)
            ->where('guides.0.slug', 'user-guide')
        );

    $this->actingAs($user)
        ->get(route('docs.chapter', ['user-guide', 'chapter-1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('docs/chapter')
            ->where('chapter.slug', 'chapter-1')
            ->where('chapter.previous', null)
            ->has('guide.chapters')
            ->has('guides', 1)
            ->where('guides.0.slug', 'user-guide')
        );
});

test('a plain user is denied the admin and installation guides', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('docs.show', 'admin-guide'))->assertForbidden();
    $this->actingAs($user)->get(route('docs.show', 'installation-guide'))->assertForbidden();
    $this->actingAs($user)->get(route('docs.chapter', ['admin-guide', 'chapter-1']))->assertForbidden();
});

test('a provider admin can open the admin guide and its chapters, with all three guides in the switcher', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->get(route('docs.show', 'admin-guide'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('docs/show')
            ->where('guide.slug', 'admin-guide')
            ->has('guides', 3)
        );

    $this->actingAs($admin)
        ->get(route('docs.chapter', ['admin-guide', 'chapter-1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('docs/chapter')
            ->where('chapter.slug', 'chapter-1')
            ->has('guides', 3)
        );
});

test('an unknown guide slug 404s', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('docs.show', 'does-not-exist'))->assertNotFound();
});

test('an unknown chapter slug within a real guide 404s', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.chapter', ['user-guide', 'chapter-999']))
        ->assertNotFound();
});
