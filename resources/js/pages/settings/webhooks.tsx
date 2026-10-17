import { Form, Head, router, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import WebhookController from '@/actions/App/Http/Controllers/Settings/WebhookController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';

type Delivery = {
    id: string;
    event: string;
    status: 'pending' | 'delivered' | 'failed';
    attempts: number;
    response_status: number | null;
    error: string | null;
    next_attempt_at: string | null;
    created_at: string | null;
};

type Webhook = {
    id: string;
    url: string;
    description: string | null;
    events: string[];
    account: { public_id: string; name: string } | null;
    disabled_at: string | null;
    consecutive_failures: number;
    created_at: string | null;
    deliveries: Delivery[];
};

type Props = {
    webhooks: Webhook[];
    accounts: { public_id: string; name: string }[];
    canManagePlatform: boolean;
    events: string[];
};

const EVENT_LABELS: Record<string, string> = {
    'operation.finished': 'A change finished on the server (applied or failed)',
    'backup.finished': 'A backup is ready or failed',
    'restore.finished': 'A restore is done or failed',
    ping: 'Test',
};

function formatDate(value: string | null): string {
    return value === null ? '' : new Date(value).toLocaleString();
}

/**
 * The new webhook's signing secret, shown once: WebhookController::store() flashes it for the
 * next page load only, and only an encrypted copy is kept for signing.
 */
function CreatedSecretBanner() {
    const secret = usePage().flash?.createdWebhookSecret;
    const [copiedText, copy] = useClipboard();

    if (!secret) {
        return null;
    }

    const IconComponent = copiedText === secret ? Check : Copy;

    return (
        <Alert>
            <AlertTitle>Copy the signing secret now</AlertTitle>
            <AlertDescription className="w-full space-y-3">
                <p>
                    This is the only time it is shown. Your receiver uses it to
                    check the Lesta-Signature header of every delivery (see the
                    API Guide). If it is lost, remove the webhook and add it
                    again.
                </p>

                <div className="flex w-full items-stretch overflow-hidden rounded-xl border border-border">
                    <input
                        type="text"
                        readOnly
                        value={secret}
                        aria-label="Webhook signing secret"
                        className="h-full w-full bg-background p-3 font-mono text-sm text-foreground outline-none"
                    />
                    <button
                        type="button"
                        onClick={() => copy(secret)}
                        className="border-l border-border px-3 hover:bg-muted"
                        aria-label="Copy secret"
                    >
                        <IconComponent className="w-4" />
                    </button>
                </div>
            </AlertDescription>
        </Alert>
    );
}

function DeliveryStatus({ delivery }: { delivery: Delivery }) {
    if (delivery.status === 'delivered') {
        return <Badge variant="secondary">Delivered</Badge>;
    }

    if (delivery.status === 'failed') {
        return <Badge variant="destructive">Failed</Badge>;
    }

    return <Badge variant="outline">Pending</Badge>;
}

export default function Webhooks({
    webhooks,
    accounts,
    canManagePlatform,
    events,
}: Props) {
    function remove(webhook: Webhook) {
        if (
            !window.confirm(
                `Remove the webhook to ${webhook.url}? Nothing more is sent to it.`,
            )
        ) {
            return;
        }

        router.delete(WebhookController.destroy.url(webhook.id), {
            preserveScroll: true,
        });
    }

    function test(webhook: Webhook) {
        router.post(
            WebhookController.test.url(webhook.id),
            {},
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title="Webhooks" />

            <h1 className="sr-only">Webhooks</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Webhooks"
                    description="LESta calls your address when a change finishes on the server, or a backup or restore finishes. Each call is signed, so your receiver can check it came from LESta."
                />

                <CreatedSecretBanner />

                <Form
                    {...WebhookController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="url">Address</Label>
                                <Input
                                    id="url"
                                    name="url"
                                    type="url"
                                    required
                                    placeholder="https://hooks.example.com/lesta"
                                />
                                <p className="text-xs text-muted-foreground">
                                    HTTPS to a public address only.
                                </p>
                                <InputError message={errors.url} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="account">For</Label>
                                <select
                                    id="account"
                                    name="account"
                                    defaultValue={accounts[0]?.public_id ?? ''}
                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    {accounts.map((account) => (
                                        <option
                                            key={account.public_id}
                                            value={account.public_id}
                                        >
                                            {account.name}
                                        </option>
                                    ))}
                                    {canManagePlatform && (
                                        <option value="">
                                            The whole platform (every account
                                            and node)
                                        </option>
                                    )}
                                </select>
                                <InputError message={errors.account} />
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Send when
                                </legend>
                                {events.map((event) => (
                                    <label
                                        key={event}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            name="events[]"
                                            value={event}
                                            defaultChecked
                                        />
                                        {EVENT_LABELS[event] ?? event}
                                    </label>
                                ))}
                                <InputError message={errors.events} />
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    Description (optional)
                                </Label>
                                <Input
                                    id="description"
                                    name="description"
                                    maxLength={255}
                                    placeholder="Monitoring"
                                />
                                <InputError message={errors.description} />
                            </div>

                            <Button disabled={processing}>Add webhook</Button>
                        </>
                    )}
                </Form>

                <div className="space-y-3">
                    <Heading variant="small" title="Your webhooks" />

                    {webhooks.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No webhooks yet.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {webhooks.map((webhook) => (
                                <li key={webhook.id} className="space-y-3 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-4">
                                        <div className="space-y-1">
                                            <div className="flex flex-wrap items-center gap-2 font-medium break-all">
                                                {webhook.url}
                                                {webhook.disabled_at && (
                                                    <Badge variant="destructive">
                                                        Switched off
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                {webhook.account
                                                    ? webhook.account.name
                                                    : 'The whole platform'}
                                                {webhook.description &&
                                                    ` · ${webhook.description}`}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {webhook.events.join(', ')}
                                            </p>
                                            {webhook.disabled_at && (
                                                <p className="text-xs text-destructive">
                                                    Switched off on{' '}
                                                    {formatDate(
                                                        webhook.disabled_at,
                                                    )}{' '}
                                                    after{' '}
                                                    {
                                                        webhook.consecutive_failures
                                                    }{' '}
                                                    failed deliveries in a row.
                                                    Sending a test switches it
                                                    back on.
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => test(webhook)}
                                            >
                                                Send a test
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => remove(webhook)}
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    </div>

                                    {webhook.deliveries.length > 0 && (
                                        <table className="w-full text-xs">
                                            <tbody>
                                                {webhook.deliveries.map(
                                                    (delivery) => (
                                                        <tr
                                                            key={delivery.id}
                                                            className="border-t align-top"
                                                        >
                                                            <td className="py-1 pr-3 whitespace-nowrap text-muted-foreground">
                                                                {formatDate(
                                                                    delivery.created_at,
                                                                )}
                                                            </td>
                                                            <td className="py-1 pr-3 font-mono">
                                                                {delivery.event}
                                                            </td>
                                                            <td className="py-1 pr-3">
                                                                <DeliveryStatus
                                                                    delivery={
                                                                        delivery
                                                                    }
                                                                />
                                                            </td>
                                                            <td className="py-1 text-muted-foreground">
                                                                {delivery.response_status !==
                                                                    null &&
                                                                    `HTTP ${delivery.response_status} · `}
                                                                {delivery.error}
                                                                {delivery.next_attempt_at &&
                                                                    ` · next try ${formatDate(delivery.next_attempt_at)}`}
                                                                {delivery.attempts >
                                                                    1 &&
                                                                    ` · ${delivery.attempts} tries`}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
