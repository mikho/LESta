<?php

use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    Notification::fake();
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'current_password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('a new email address needs the current password', function (?string $password) {
    $user = User::factory()->create();
    $email = $user->email;

    $this->actingAs($user)
        ->patch(route('profile.update'), array_filter(['name' => 'Test User', 'email' => 'someone-else@example.com', 'current_password' => $password]))
        ->assertSessionHasErrors('current_password');

    expect($user->refresh()->email)->toBe($email);
})->with([null, 'wrong-password']);

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('the only owner of an account cannot delete their login', function () {
    $membership = Membership::factory()->owner()->create();
    $owner = $membership->user;

    $this->actingAs($owner)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('deletion');

    expect($owner->fresh())->not->toBeNull();
});

test('an owner can delete their login when the account has another owner', function () {
    $membership = Membership::factory()->owner()->create();
    Membership::factory()->owner()->create(['account_id' => $membership->account_id]);

    $this->actingAs($membership->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($membership->user->fresh())->toBeNull();
    $this->assertDatabaseHas('audit_events', ['action' => 'user.deleted', 'actor_id' => $membership->user_id]);
});

test('the export holds the users own data and no secrets', function () {
    $user = User::factory()->create(['name' => 'Ada Export']);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('profile.export'))
        ->assertOk()
        ->assertDownload();

    $body = $response->streamedContent();

    expect($body)->toContain('Ada Export')->toContain($user->email)
        ->not->toContain($user->password);
});
