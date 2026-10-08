import { Form, Head, Link } from '@inertiajs/react';
import MailingListController from '@/actions/App/Http/Controllers/Mail/MailingListController';
import { FormCheckbox } from '@/components/form-checkbox';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

type Member = { uuid: string; email: string };

type List = {
    uuid: string;
    local_part: string;
    owner_email: string;
    post_policy: 'members' | 'anyone' | 'owner';
    subject_prefix: string;
    reply_to_list: boolean;
    members: Member[];
};

type Props = {
    mailDomain: { uuid: string; domain: string };
    lists: List[];
    limits: { lists: number; members: number };
};

const POLICY_LABELS: Record<List['post_policy'], string> = {
    members: 'Only the list members and its owner',
    anyone: 'Anyone',
    owner: 'Only the list owner',
};

function ListSettingsFields({
    list,
    errors,
}: {
    list?: List;
    errors: Record<string, string | undefined>;
}) {
    const id = (name: string) => `${name}_${list?.uuid ?? 'new'}`;

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={id('owner_email')}>
                    Owner (gets bounces and mail to the -owner address)
                </Label>
                <Input
                    id={id('owner_email')}
                    name="owner_email"
                    type="email"
                    defaultValue={list?.owner_email}
                    autoComplete="off"
                    required
                />
                <InputError message={errors.owner_email} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={id('post_policy')}>Who may post</Label>
                <Select
                    name="post_policy"
                    defaultValue={list?.post_policy ?? 'members'}
                >
                    <SelectTrigger id={id('post_policy')}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {(
                            Object.keys(POLICY_LABELS) as List['post_policy'][]
                        ).map((policy) => (
                            <SelectItem key={policy} value={policy}>
                                {POLICY_LABELS[policy]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.post_policy} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={id('subject_prefix')}>
                    Subject prefix (optional)
                </Label>
                <Input
                    id={id('subject_prefix')}
                    name="subject_prefix"
                    defaultValue={list?.subject_prefix}
                    placeholder="[News]"
                    maxLength={40}
                    autoComplete="off"
                />
                <InputError message={errors.subject_prefix} />
            </div>

            <div className="flex items-center gap-2">
                <FormCheckbox
                    id={id('reply_to_list')}
                    name="reply_to_list"
                    defaultChecked={list?.reply_to_list ?? false}
                />
                <Label htmlFor={id('reply_to_list')}>
                    Replies go to the whole list
                </Label>
            </div>
        </>
    );
}

export default function Lists({ mailDomain, lists, limits }: Props) {
    return (
        <>
            <Head title={`Mailing lists for ${mailDomain.domain}`} />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Mailing lists"
                        description={`Mail sent to a list address is delivered to every member of ${mailDomain.domain}'s list`}
                    />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/mail/${mailDomain.uuid}/edit`}>
                            Back to mail domain
                        </Link>
                    </Button>
                </div>

                <section className="space-y-4 rounded-lg border p-4">
                    <Heading variant="small" title="New list" />

                    <Form
                        {...MailingListController.store.form(mailDomain)}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="local_part">
                                        List address
                                    </Label>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            id="local_part"
                                            name="local_part"
                                            placeholder="news"
                                            autoComplete="off"
                                            required
                                        />
                                        <span className="text-sm whitespace-nowrap text-muted-foreground">
                                            @{mailDomain.domain}
                                        </span>
                                    </div>
                                    <InputError message={errors.local_part} />
                                </div>

                                <ListSettingsFields errors={errors} />

                                <Button
                                    disabled={processing}
                                    data-test="create-list-button"
                                >
                                    Create list
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                {lists.length === 0 ? (
                    <p
                        className="text-sm text-muted-foreground"
                        data-test="no-lists"
                    >
                        No mailing lists yet.
                    </p>
                ) : (
                    lists.map((list) => (
                        <section
                            key={list.uuid}
                            className="space-y-4 rounded-lg border p-4"
                            data-test="mailing-list"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h3 className="font-mono text-sm break-all">
                                        {list.local_part}@{mailDomain.domain}
                                    </h3>
                                    <p className="text-xs text-muted-foreground">
                                        {list.members.length} of{' '}
                                        {limits.members} members. Owner address:{' '}
                                        {list.local_part}-owner@
                                        {mailDomain.domain}
                                    </p>
                                </div>
                                <Form
                                    {...MailingListController.destroy.form({
                                        mailDomain: mailDomain.uuid,
                                        list: list.uuid,
                                    })}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={processing}
                                            aria-label={`Delete the list ${list.local_part}`}
                                        >
                                            Delete list
                                        </Button>
                                    )}
                                </Form>
                            </div>

                            <Form
                                {...MailingListController.update.form({
                                    mailDomain: mailDomain.uuid,
                                    list: list.uuid,
                                })}
                                options={{ preserveScroll: true }}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <ListSettingsFields
                                            list={list}
                                            errors={errors}
                                        />
                                        <Button
                                            variant="secondary"
                                            disabled={processing}
                                        >
                                            Save settings
                                        </Button>
                                    </>
                                )}
                            </Form>

                            <div className="space-y-3">
                                <Heading variant="small" title="Members" />

                                {list.members.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nobody has joined yet, so mail to this
                                        list is refused until you add members.
                                    </p>
                                ) : (
                                    <ul className="max-h-72 divide-y overflow-auto rounded-md border text-sm">
                                        {list.members.map((member) => (
                                            <li
                                                key={member.uuid}
                                                className="flex items-center justify-between gap-3 p-2"
                                            >
                                                <span className="font-mono break-all">
                                                    {member.email}
                                                </span>
                                                <Form
                                                    {...MailingListController.destroyMember.form(
                                                        {
                                                            mailDomain:
                                                                mailDomain.uuid,
                                                            list: list.uuid,
                                                            member: member.uuid,
                                                        },
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                            aria-label={`Remove ${member.email} from ${list.local_part}`}
                                                        >
                                                            Remove
                                                        </Button>
                                                    )}
                                                </Form>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <Form
                                    {...MailingListController.storeMembers.form(
                                        {
                                            mailDomain: mailDomain.uuid,
                                            list: list.uuid,
                                        },
                                    )}
                                    options={{ preserveScroll: true }}
                                    resetOnSuccess
                                    className="space-y-2"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <Label
                                                htmlFor={`emails_${list.uuid}`}
                                            >
                                                Add members (paste addresses
                                                separated by commas, spaces or
                                                new lines)
                                            </Label>
                                            <Textarea
                                                id={`emails_${list.uuid}`}
                                                name="emails"
                                                rows={3}
                                                placeholder="ann@example.com, bob@example.com"
                                                required
                                            />
                                            <InputError
                                                message={errors.emails}
                                            />
                                            <Button
                                                variant="secondary"
                                                disabled={processing}
                                            >
                                                Add members
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </div>
                        </section>
                    ))
                )}

                <p className="text-sm text-muted-foreground">
                    {lists.length} of {limits.lists} lists used. There is no
                    self-service unsubscribe link yet, so the list owner removes
                    members. Messages keep the sender&apos;s own address in
                    From, so a sender whose domain publishes a strict DMARC
                    policy may see their list posts rejected by some
                    members&apos; mail providers.
                </p>
            </div>
        </>
    );
}
