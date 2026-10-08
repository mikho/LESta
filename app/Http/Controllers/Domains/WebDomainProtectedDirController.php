<?php

namespace App\Http\Controllers\Domains;

use App\Actions\Domains\RerenderWebDomain;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\WebDomain;
use App\Models\WebDomainProtectedDir;
use App\Models\WebDomainProtectedDirUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A web domain's password-protected directories and their logins. Every change re-renders the
 * domain's nginx vhost. Passwords are hashed here and never stored or logged.
 */
class WebDomainProtectedDirController extends Controller
{
    private const int MIN_PASSWORD_LENGTH = 8;

    public function store(Request $request, WebDomain $webDomain): RedirectResponse
    {
        Gate::authorize('update', $webDomain);

        $request->merge(['path' => '/'.trim(trim((string) $request->input('path')), '/')]);

        $data = $request->validate([
            'path' => [
                'required',
                'string',
                'max:200',
                'regex:'.WebDomainProtectedDir::PATH_PATTERN,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $path = (string) $value;

                    if (str_contains($path, '//') || str_contains($path, '..') || str_starts_with($path, '/.well-known') || $path === '/__lesta-health__') {
                        $fail(__('That path cannot be protected.'));
                    }
                },
            ],
            'realm' => ['required', 'string', 'regex:'.WebDomainProtectedDir::REALM_PATTERN],
            'username' => ['required', 'string', 'regex:'.WebDomainProtectedDir::USERNAME_PATTERN],
            'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'max:128'],
        ], $this->messages());

        if ($webDomain->protectedDirs()->count() >= WebDomainProtectedDir::MAX_PER_DOMAIN) {
            throw ValidationException::withMessages(['path' => __('A domain can have at most :limit protected directories.', ['limit' => WebDomainProtectedDir::MAX_PER_DOMAIN])]);
        }

        if ($webDomain->protectedDirs()->where('path', $data['path'])->exists()) {
            throw ValidationException::withMessages(['path' => __('That directory is already protected.')]);
        }

        $directory = DB::transaction(function () use ($webDomain, $data): WebDomainProtectedDir {
            $directory = $webDomain->protectedDirs()->create(['path' => $data['path'], 'realm' => $data['realm']]);
            $directory->users()->create(['username' => $data['username'], 'password_hash' => WebDomainProtectedDirUser::hashPassword($data['password'])]);

            return $directory;
        });

        $this->audit($request, $directory, 'web_domain.protected_dir_created');
        app(RerenderWebDomain::class)->handle($webDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Directory protected.')]);

        return to_route('domains.edit', $webDomain);
    }

    public function destroy(Request $request, WebDomain $webDomain, WebDomainProtectedDir $directory): RedirectResponse
    {
        Gate::authorize('update', $webDomain);

        abort_unless($directory->web_domain_id === $webDomain->id, 404);

        $this->audit($request, $directory, 'web_domain.protected_dir_deleted');
        $directory->delete();
        app(RerenderWebDomain::class)->handle($webDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Protection removed.')]);

        return to_route('domains.edit', $webDomain);
    }

    /**
     * Adds a login, or replaces the password of an existing one (the way to change a password).
     */
    public function storeUser(Request $request, WebDomain $webDomain, WebDomainProtectedDir $directory): RedirectResponse
    {
        Gate::authorize('update', $webDomain);

        abort_unless($directory->web_domain_id === $webDomain->id, 404);

        $data = $request->validate([
            'username' => ['required', 'string', 'regex:'.WebDomainProtectedDir::USERNAME_PATTERN],
            'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'max:128'],
        ], $this->messages());

        $existing = $directory->users()->where('username', $data['username'])->first();

        if ($existing === null && $directory->users()->count() >= WebDomainProtectedDir::MAX_USERS) {
            throw ValidationException::withMessages(['username' => __('A directory can have at most :limit logins.', ['limit' => WebDomainProtectedDir::MAX_USERS])]);
        }

        $hash = WebDomainProtectedDirUser::hashPassword($data['password']);

        if ($existing !== null) {
            $existing->update(['password_hash' => $hash]);
            $this->audit($request, $existing, 'web_domain.protected_dir_user_password_changed');
        } else {
            $user = $directory->users()->create(['username' => $data['username'], 'password_hash' => $hash]);
            $this->audit($request, $user, 'web_domain.protected_dir_user_created');
        }

        app(RerenderWebDomain::class)->handle($webDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => $existing !== null ? __('Password changed.') : __('Login added.')]);

        return to_route('domains.edit', $webDomain);
    }

    public function destroyUser(Request $request, WebDomain $webDomain, WebDomainProtectedDir $directory, WebDomainProtectedDirUser $user): RedirectResponse
    {
        Gate::authorize('update', $webDomain);

        abort_unless($directory->web_domain_id === $webDomain->id && $user->protected_dir_id === $directory->id, 404);

        if ($directory->users()->count() <= 1) {
            throw ValidationException::withMessages(['username' => __('A protected directory needs at least one login. Remove the protection instead.')]);
        }

        $this->audit($request, $user, 'web_domain.protected_dir_user_deleted');
        $user->delete();
        app(RerenderWebDomain::class)->handle($webDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Login removed.')]);

        return to_route('domains.edit', $webDomain);
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'path.regex' => __('Use a plain folder path such as /members, with letters, numbers and . _ ~ % + - only.'),
            'realm.regex' => __('The name shown in the login box can use letters, numbers, spaces and . , _ - (up to 64).'),
            'username.regex' => __('A username can use letters, numbers and . _ - (up to 32).'),
            'password.min' => __('The password must be at least :min characters.'),
        ];
    }

    private function audit(Request $request, Model $subject, string $action): void
    {
        AuditEvent::create([
            'actor_type' => $request->user()->getMorphClass(),
            'actor_id' => $request->user()->getKey(),
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'action' => $action,
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
