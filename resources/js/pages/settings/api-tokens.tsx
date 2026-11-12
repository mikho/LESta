import { Form, Head, router, usePage } from '@inertiajs/react';
import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import { useConfirm } from '@/components/confirm-action-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { OneTimeSecretBanner } from '@/components/one-time-secret-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';

const formatDateTimeOr = (value: string | null): string =>
    value === null ? 'Never' : formatDateTime(value);

type ApiToken = {
    id: string;
    name: string;
    abilities: string[];
    account: { id: number; name: string } | null;
    expires_at: string;
    expires_soon: boolean;
    expired: boolean;
    last_used_at: string | null;
    last_used_ip: string | null;
    created_at: string | null;
};

type Props = {
    tokens: ApiToken[];
    abilities: string[];
    accounts: { id: number; name: string }[];
    defaultDays: number;
    maxDays: number;
};

/** The resource families the abilities come in, in the order they are shown. */
const FAMILIES: { key: string; label: string }[] = [
    { key: 'web', label: 'Web domains' },
    { key: 'dns', label: 'DNS' },
    { key: 'mail', label: 'Mail' },
    { key: 'databases', label: 'Databases' },
    { key: 'cron', label: 'Cron jobs' },
    { key: 'backups', label: 'Backups' },
    { key: 'usage', label: 'Usage' },
    { key: 'admin', label: 'Platform administration' },
];

/**
 * The newly created token, shown once: ApiTokenController::store() flashes it for exactly the next
 * page load, and only its hash is stored.
 */
function CreatedTokenBanner() {
    const token = usePage().flash?.createdToken;

    if (!token) {
        return null;
    }

    return (
        <OneTimeSecretBanner
            title="Copy your new token now"
            secret={token}
            inputLabel="New API token"
            copyLabel="Copy token"
        >
            This is the only time the token is shown. Only a hash of it is
            stored; create a new one if it is lost. Send it in the Authorization
            header as <code className="font-mono">Bearer &lt;token&gt;</code>.
        </OneTimeSecretBanner>
    );
}

export default function ApiTokens({
    tokens,
    abilities,
    accounts,
    defaultDays,
    maxDays,
}: Props) {
    const families = FAMILIES.filter((family) =>
        abilities.some((ability) => ability.startsWith(`${family.key}:`)),
    );

    const [confirm, confirmDialog] = useConfirm();

    async function revoke(token: ApiToken) {
        if (
            !(await confirm({
                title: `Revoke ${token.name}?`,
                description:
                    'Scripts using this token stop working at once. This cannot be undone.',
                confirmLabel: 'Revoke token',
                destructive: true,
            }))
        ) {
            return;
        }

        router.delete(ApiTokenController.destroy.url(token.id), {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="API tokens" />
            {confirmDialog}

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="API tokens"
                    description="Tokens let scripts use the LESta API as you. A token can never do more than you can, only less."
                />

                <CreatedTokenBanner />

                <Form
                    {...ApiTokenController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    maxLength={100}
                                    placeholder="Deploy script"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <fieldset className="grid gap-3">
                                <legend className="mb-2 text-sm font-medium">
                                    Abilities
                                </legend>
                                {families.map((family) => (
                                    <div
                                        key={family.key}
                                        className="flex flex-wrap items-center gap-x-6 gap-y-2"
                                    >
                                        <span className="w-48 text-sm text-muted-foreground">
                                            {family.label}
                                        </span>
                                        {['read', 'write']
                                            .filter((mode) =>
                                                abilities.includes(
                                                    `${family.key}:${mode}`,
                                                ),
                                            )
                                            .map((mode) => {
                                                const ability = `${family.key}:${mode}`;

                                                return (
                                                    <label
                                                        key={ability}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <Checkbox
                                                            name="abilities[]"
                                                            value={ability}
                                                        />
                                                        {mode === 'read'
                                                            ? 'Read'
                                                            : 'Write'}
                                                    </label>
                                                );
                                            })}
                                    </div>
                                ))}
                                <InputError message={errors.abilities} />
                            </fieldset>

                            {accounts.length > 0 && (
                                <div className="grid gap-2">
                                    <Label htmlFor="account_id">
                                        Restrict to one account
                                    </Label>
                                    <select
                                        id="account_id"
                                        name="account_id"
                                        defaultValue=""
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option value="">
                                            Every account I can reach
                                        </option>
                                        {accounts.map((account) => (
                                            <option
                                                key={account.id}
                                                value={account.id}
                                            >
                                                {account.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.account_id} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="expires_in_days">
                                    Expires after (days, at most {maxDays})
                                </Label>
                                <Input
                                    id="expires_in_days"
                                    name="expires_in_days"
                                    type="number"
                                    min={1}
                                    max={maxDays}
                                    defaultValue={defaultDays}
                                    required
                                    className="w-32"
                                />
                                <InputError message={errors.expires_in_days} />
                            </div>

                            <Button disabled={processing}>Create token</Button>
                        </>
                    )}
                </Form>

                <div className="space-y-3">
                    <Heading variant="small" title="Your tokens" />

                    {tokens.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            You have no API tokens.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {tokens.map((token) => (
                                <li
                                    key={token.id}
                                    className="flex flex-wrap items-start justify-between gap-4 p-4"
                                >
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2 font-medium">
                                            {token.name}
                                            {token.expired ? (
                                                <Badge variant="destructive">
                                                    Expired
                                                </Badge>
                                            ) : (
                                                token.expires_soon && (
                                                    <Badge variant="secondary">
                                                        Expires soon
                                                    </Badge>
                                                )
                                            )}
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {token.abilities.join(', ')}
                                            {token.account &&
                                                ` · only ${token.account.name}`}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Expires{' '}
                                            {formatDateTimeOr(token.expires_at)}{' '}
                                            · Last used{' '}
                                            {formatDateTimeOr(
                                                token.last_used_at,
                                            )}
                                            {token.last_used_ip &&
                                                ` from ${token.last_used_ip}`}
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => revoke(token)}
                                    >
                                        Revoke
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
