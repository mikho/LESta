import { CircleCheck, Clock, LoaderCircle, TriangleAlert } from 'lucide-react';

/**
 * The one place a provisioning status is turned into words and color. The states are the system's
 * fixed status set (DESIGN.md, "The Four Statuses Rule"); every list uses this instead of keeping
 * its own copy. All text pairs meet 4.5:1 in light and dark.
 */
const labels: Record<string, string> = {
    pending: 'Pending',
    dispatched: 'In progress',
    applied: 'Applied',
    already_applied: 'Applied',
    rejected: 'Rejected',
    failed: 'Failed',
    degraded: 'Degraded',
};

const classes: Record<string, string> = {
    pending:
        'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    dispatched:
        'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    applied:
        'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    already_applied:
        'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    rejected: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    degraded:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    unknown:
        'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
};

export function ProvisioningBadge({ status }: { status: string | null }) {
    const key = status !== null && status in classes ? status : 'unknown';

    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${classes[key]}`}
        >
            {status !== null && status in labels
                ? labels[status]
                : 'No operation yet'}
        </span>
    );
}

/**
 * The same status for a list that is read to find what is wrong: a healthy or empty state is
 * quiet text, so only an exception carries color. A failed, rejected or degraded operation also
 * shows the reason the node reported, so the admin does not have to go looking for it.
 */
export function QuietProvisioningStatus({
    status,
    reason,
}: {
    status: string | null;
    reason?: string | null;
}) {
    if (
        status === null ||
        status === 'applied' ||
        status === 'already_applied'
    ) {
        return (
            <span className="text-muted-foreground">
                {status === null ? 'No operation yet' : 'Applied'}
            </span>
        );
    }

    return (
        <span className="inline-flex flex-col items-start gap-1">
            <ProvisioningBadge status={status} />
            {reason && (
                <span
                    className="block max-w-xs truncate text-xs text-muted-foreground"
                    title={reason}
                >
                    {reason}
                </span>
            )}
        </span>
    );
}

const isException = (status: string | null): boolean =>
    status === 'rejected' || status === 'failed' || status === 'degraded';

/**
 * A customer's own resource in a list: whether it is active or suspended, and, only while the
 * server is still working on a change or could not apply one, the provisioning badge with the
 * reason the server gave. A healthy row stays quiet.
 */
export function ResourceStatus({
    suspendedAt,
    suspensionSource,
    status,
    reason,
}: {
    suspendedAt: string | null;
    suspensionSource: string | null;
    status: string | null;
    reason?: string | null;
}) {
    const settled =
        status === null || status === 'applied' || status === 'already_applied';

    return (
        <span className="inline-flex flex-col items-start gap-1">
            {suspendedAt ? (
                <span className="text-red-700 dark:text-red-400">
                    {suspensionSource === 'cascade'
                        ? 'Suspended with the account'
                        : suspensionSource === 'provider' ||
                            suspensionSource === 'reseller'
                          ? 'Suspended by your provider'
                          : 'Suspended'}
                </span>
            ) : (
                settled && <span className="text-muted-foreground">Active</span>
            )}
            {!settled && <ProvisioningBadge status={status} />}
            {isException(status) && reason && (
                <span
                    className="block max-w-xs truncate text-xs text-muted-foreground"
                    title={reason}
                >
                    {reason}
                </span>
            )}
        </span>
    );
}

const relativeTime = (iso: string): string => {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(0, 'second');
};

const panelCopy: Record<
    string,
    {
        title: string;
        body: string;
        tone: 'quiet' | 'busy' | 'ok' | 'warn' | 'bad';
    }
> = {
    none: {
        title: 'Not sent to the server yet',
        body: 'It will be applied shortly.',
        tone: 'quiet',
    },
    pending: {
        title: 'Waiting to be applied',
        body: 'Your change is saved and queued for the server. This page updates by itself.',
        tone: 'busy',
    },
    dispatched: {
        title: 'Applying your change',
        body: 'The server is applying it now. This page updates by itself.',
        tone: 'busy',
    },
    applied: {
        title: 'Up to date on the server',
        body: 'Everything below is what the server is running.',
        tone: 'ok',
    },
    rejected: {
        title: 'The server refused the last change',
        body: 'Check the settings below and save again. If it keeps happening, contact your provider.',
        tone: 'bad',
    },
    failed: {
        title: 'The server could not apply the last change',
        body: 'Save again to retry. If it keeps failing, contact your provider.',
        tone: 'bad',
    },
    degraded: {
        title: 'The server no longer matches these settings',
        body: 'Something changed on the server. Saving again applies these settings once more.',
        tone: 'warn',
    },
};

const toneClasses: Record<string, string> = {
    quiet: 'border-border',
    busy: 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/40',
    ok: 'border-border',
    warn: 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40',
    bad: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40',
};

/**
 * The top of a customer's edit page: what the server is doing with this resource, in a sentence,
 * with the reason when it could not apply a change and what to do about it. Announced politely,
 * so a change that finishes while the page is open is read out.
 */
export function ProvisioningPanel({
    status,
    reason,
    updatedAt,
}: {
    status: string | null;
    reason?: string | null;
    updatedAt?: string | null;
}) {
    const key =
        status === 'already_applied'
            ? 'applied'
            : status !== null && status in panelCopy
              ? status
              : 'none';
    const copy = panelCopy[key];
    const Icon =
        copy.tone === 'busy'
            ? LoaderCircle
            : copy.tone === 'ok'
              ? CircleCheck
              : copy.tone === 'quiet'
                ? Clock
                : TriangleAlert;

    return (
        <div
            role="status"
            aria-live="polite"
            className={`flex items-start gap-3 rounded-lg border p-4 text-sm ${toneClasses[copy.tone]}`}
        >
            <Icon
                aria-hidden
                className={`mt-0.5 size-4 shrink-0 ${copy.tone === 'busy' ? 'animate-spin text-blue-700 dark:text-blue-300' : copy.tone === 'ok' ? 'text-green-700 dark:text-green-400' : copy.tone === 'bad' ? 'text-red-700 dark:text-red-400' : copy.tone === 'warn' ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'}`}
            />
            <div className="min-w-0 space-y-1">
                <p className="font-medium">
                    {copy.title}
                    {key === 'applied' && updatedAt && (
                        <span
                            className="font-normal text-muted-foreground"
                            title={new Date(updatedAt).toLocaleString()}
                        >
                            {' '}
                            · {relativeTime(updatedAt)}
                        </span>
                    )}
                </p>
                {reason && copy.tone !== 'ok' && copy.tone !== 'busy' && (
                    <p className="font-mono text-xs break-words text-foreground/80">
                        {reason}
                    </p>
                )}
                <p className="text-muted-foreground">{copy.body}</p>
            </div>
        </div>
    );
}
