/**
 * The one place a provisioning status is turned into words and color. The five states are the
 * system's fixed status set (DESIGN.md, "The Four Statuses Rule"); every list uses this instead of
 * keeping its own copy. All text pairs meet 4.5:1 in light and dark.
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
