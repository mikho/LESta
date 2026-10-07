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
