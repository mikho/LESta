<?php

namespace App\Http\Controllers\Mail;

use App\Actions\Mail\SyncMailDomainLists;
use App\Http\Controllers\Controller;
use App\Models\MailDomain;
use App\Models\MailingList;
use App\Models\MailingListMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A mail domain's mailing lists and their members. The domain's owner manages everything here;
 * every change sends the domain's payload to its node.
 */
class MailingListController extends Controller
{
    public function index(MailDomain $mailDomain): Response
    {
        Gate::authorize('update', $mailDomain);

        return Inertia::render('mail/lists', [
            'mailDomain' => ['uuid' => $mailDomain->uuid, 'domain' => $mailDomain->domain],
            'lists' => $mailDomain->mailingLists()->with('members')->orderBy('local_part')->get()->map(fn (MailingList $list): array => [
                'uuid' => $list->uuid,
                'local_part' => $list->local_part,
                'owner_email' => $list->owner_email,
                'post_policy' => $list->post_policy,
                'subject_prefix' => $list->subject_prefix ?? '',
                'reply_to_list' => $list->reply_to_list,
                'members' => $list->members->sortBy('email')->map(fn (MailingListMember $member): array => ['uuid' => $member->uuid, 'email' => $member->email])->values()->all(),
            ])->all(),
            'limits' => ['lists' => MailingList::MAX_PER_DOMAIN, 'members' => MailingList::MAX_MEMBERS],
        ]);
    }

    public function store(Request $request, MailDomain $mailDomain): RedirectResponse
    {
        Gate::authorize('update', $mailDomain);

        $request->merge([
            'local_part' => mb_strtolower(trim((string) $request->input('local_part'))),
            'owner_email' => mb_strtolower(trim((string) $request->input('owner_email'))),
        ]);

        $data = $request->validate($this->settingsRules() + ['local_part' => ['required', 'string', 'max:64', 'regex:'.MailingList::LOCAL_PART_PATTERN]], $this->messages());

        if ($mailDomain->mailingLists()->count() >= MailingList::MAX_PER_DOMAIN) {
            throw ValidationException::withMessages(['local_part' => __('A domain can have at most :limit mailing lists.', ['limit' => MailingList::MAX_PER_DOMAIN])]);
        }

        $this->assertNameIsFree($mailDomain, $data['local_part']);

        $list = $mailDomain->mailingLists()->create($this->attributes($data) + ['local_part' => $data['local_part']]);

        app(SyncMailDomainLists::class)->handle($request->user(), $mailDomain, $list, 'mailing_list.created');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mailing list created.')]);

        return to_route('mail.lists.index', $mailDomain);
    }

    public function update(Request $request, MailDomain $mailDomain, MailingList $list): RedirectResponse
    {
        Gate::authorize('update', $mailDomain);

        abort_unless($list->mail_domain_id === $mailDomain->id, 404);

        $request->merge(['owner_email' => mb_strtolower(trim((string) $request->input('owner_email')))]);

        $data = $request->validate($this->settingsRules(), $this->messages());

        $list->update($this->attributes($data));

        app(SyncMailDomainLists::class)->handle($request->user(), $mailDomain, $list, 'mailing_list.updated');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mailing list updated.')]);

        return to_route('mail.lists.index', $mailDomain);
    }

    public function destroy(Request $request, MailDomain $mailDomain, MailingList $list): RedirectResponse
    {
        Gate::authorize('update', $mailDomain);

        abort_unless($list->mail_domain_id === $mailDomain->id, 404);

        $list->delete();

        app(SyncMailDomainLists::class)->handle($request->user(), $mailDomain, $list, 'mailing_list.deleted');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mailing list deleted.')]);

        return to_route('mail.lists.index', $mailDomain);
    }

    /**
     * Adds every address in the pasted text (separated by spaces, commas, semicolons or lines);
     * an address already on the list is skipped, and one invalid address refuses the whole paste.
     */
    public function storeMembers(Request $request, MailDomain $mailDomain, MailingList $list): RedirectResponse
    {
        Gate::authorize('update', $mailDomain);

        abort_unless($list->mail_domain_id === $mailDomain->id, 404);

        $request->validate(['emails' => ['required', 'string', 'max:50000']]);

        $emails = array_values(array_unique(array_map('mb_strtolower', preg_split('/[\s,;]+/', (string) $request->input('emails'), -1, PREG_SPLIT_NO_EMPTY) ?: [])));

        foreach ($emails as $email) {
            if (preg_match(MailingList::EMAIL_PATTERN, $email) !== 1) {
                throw ValidationException::withMessages(['emails' => __('":email" is not a plain email address.', ['email' => mb_substr($email, 0, 80)])]);
            }
        }

        $existing = $list->members()->pluck('email')->all();
        $new = array_values(array_diff($emails, $existing));

        if (count($existing) + count($new) > MailingList::MAX_MEMBERS) {
            throw ValidationException::withMessages(['emails' => __('A list can have at most :limit members.', ['limit' => MailingList::MAX_MEMBERS])]);
        }

        if ($new === []) {
            throw ValidationException::withMessages(['emails' => __('Everyone you entered is already on the list.')]);
        }

        DB::transaction(function () use ($list, $new): void {
            foreach ($new as $email) {
                $list->members()->create(['email' => $email]);
            }
        });

        app(SyncMailDomainLists::class)->handle($request->user(), $mailDomain, $list, 'mailing_list.members_added');

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count member added.|:count members added.', count($new))]);

        return to_route('mail.lists.index', $mailDomain);
    }

    public function destroyMember(Request $request, MailDomain $mailDomain, MailingList $list, MailingListMember $member): RedirectResponse
    {
        Gate::authorize('update', $mailDomain);

        abort_unless($list->mail_domain_id === $mailDomain->id && $member->mailing_list_id === $list->id, 404);

        $member->delete();

        app(SyncMailDomainLists::class)->handle($request->user(), $mailDomain, $list, 'mailing_list.member_removed');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('mail.lists.index', $mailDomain);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsRules(): array
    {
        return [
            'owner_email' => ['required', 'string', 'regex:'.MailingList::EMAIL_PATTERN],
            'post_policy' => ['required', Rule::in(MailingList::POST_POLICIES)],
            'subject_prefix' => ['nullable', 'string', 'regex:'.MailingList::SUBJECT_PREFIX_PATTERN],
            'reply_to_list' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'owner_email' => $data['owner_email'],
            'post_policy' => $data['post_policy'],
            'subject_prefix' => ($data['subject_prefix'] ?? '') === '' ? null : trim((string) $data['subject_prefix']),
            'reply_to_list' => (bool) ($data['reply_to_list'] ?? false),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'local_part.regex' => __('A list name can use lower-case letters, numbers and . _ + - (not at the start or end).'),
            'owner_email.regex' => __('The owner must be a plain email address such as owner@example.com.'),
            'subject_prefix.regex' => __('The subject prefix can use letters, numbers, spaces and . _ [ ] - only (up to 40).'),
        ];
    }

    /**
     * A list answers to <name>@ and <name>-owner@: neither may be used by a mailbox or another list.
     */
    private function assertNameIsFree(MailDomain $mailDomain, string $name): void
    {
        if (str_ends_with($name, '-owner')) {
            throw ValidationException::withMessages(['local_part' => __('A list name cannot end in -owner, that address is reserved for the list owner.')]);
        }

        $mine = [$name, $name.'-owner'];

        $mailbox = $mailDomain->accounts()->whereIn(DB::raw('lower(local_part)'), $mine)->exists();
        $list = $mailDomain->mailingLists()->where('local_part', $name)->exists();

        if ($mailbox || $list) {
            throw ValidationException::withMessages(['local_part' => __('That name is already used by a mailbox or another list.')]);
        }
    }
}
