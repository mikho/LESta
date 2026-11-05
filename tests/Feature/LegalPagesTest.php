<?php

use Illuminate\Notifications\Messages\MailMessage;
use Inertia\Testing\AssertableInertia as Assert;

it('serves the public legal pages without signing in', function (string $path, string $component) {
    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['/privacy', 'legal/privacy'],
    ['/terms', 'legal/terms'],
    ['/cookies', 'legal/cookies'],
]);

it('shares the configured business details', function () {
    config(['legal.company_name' => 'Example AB', 'legal.contact_email' => 'hello@example.test']);

    $this->get('/privacy')->assertInertia(fn (Assert $page) => $page
        ->where('legal.company_name', 'Example AB')
        ->where('legal.contact_email', 'hello@example.test'));
});

it('puts a privacy link and a no-marketing note in the mail footer', function () {
    $html = (string) (new MailMessage)->line('Body')->render();

    expect($html)->toContain(route('legal.privacy'))->toContain('no marketing email');
});
