import { Form, Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AccountBackupController from '@/actions/App/Http/Controllers/Backups/AccountBackupController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NoAccountNotice } from '@/components/no-account-notice';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

type Part = 'files' | 'databases' | 'mail';

type Backup = {
    uuid: string;
    label: string | null;
    kind: 'manual' | 'before_restore';
    node: string;
    status: 'running' | 'ready' | 'failed';
    error: string | null;
    parts: Part[];
    size_bytes: number | null;
    skipped: string[];
    created_at: string | null;
    restore: {
        status: 'running' | 'applied' | 'failed';
        at: string | null;
        restored: Part[];
        skipped: string[];
        error: string | null;
    } | null;
};

type NodeInfo = {
    uuid: string;
    name: string;
    parts: Part[];
    counts: Record<Part, number>;
    can_back_up: boolean;
};

type Props = {
    backups: Backup[] | null;
    nodes: NodeInfo[];
    keep: number;
    can_manage?: boolean;
};

const PART_LABELS: Record<Part, string> = {
    files: 'Website files',
    databases: 'Databases',
    mail: 'Mailboxes',
};

function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(bytes < 100 * 1024 * 1024 ? 1 : 0)} MB`;
}

function PartChecklist({
    available,
    selected,
    onChange,
    idPrefix,
    counts,
}: {
    available: Part[];
    selected: Part[];
    onChange: (next: Part[]) => void;
    idPrefix: string;
    counts?: Record<Part, number>;
}) {
    return (
        <div className="space-y-2">
            {(Object.keys(PART_LABELS) as Part[]).map((part) => {
                const enabled = available.includes(part);

                return (
                    <div key={part} className="flex items-center gap-2">
                        <Checkbox
                            id={`${idPrefix}_${part}`}
                            checked={selected.includes(part)}
                            disabled={!enabled}
                            onCheckedChange={(checked) =>
                                onChange(
                                    checked
                                        ? [...selected, part]
                                        : selected.filter((p) => p !== part),
                                )
                            }
                        />
                        <Label htmlFor={`${idPrefix}_${part}`}>
                            {PART_LABELS[part]}
                            {counts && enabled ? ` (${counts[part]})` : ''}
                            {enabled ? '' : ' (none on this server)'}
                        </Label>
                    </div>
                );
            })}
        </div>
    );
}

function BackUpNow({ node }: { node: NodeInfo }) {
    const [parts, setParts] = useState<Part[]>(node.parts);

    return (
        <Form
            {...AccountBackupController.store.form()}
            options={{ preserveScroll: true }}
            transform={(data) => ({ ...data, node: node.uuid, parts })}
            className="space-y-3 rounded-lg border p-4"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-medium">{node.name}</h3>

                    <PartChecklist
                        available={node.parts}
                        selected={parts}
                        onChange={setParts}
                        idPrefix={`backup_${node.uuid}`}
                        counts={node.counts}
                    />

                    <InputError message={errors.parts} />
                    <InputError message={errors.backup} />
                    <InputError message={errors.node} />

                    {!node.can_back_up && (
                        <p className="text-sm text-muted-foreground">
                            Backups are not available on this server yet.
                        </p>
                    )}

                    <Button
                        disabled={
                            processing ||
                            !node.can_back_up ||
                            parts.length === 0
                        }
                        data-test="back-up-now-button"
                    >
                        Back up now
                    </Button>
                </>
            )}
        </Form>
    );
}

function RestoreDialog({
    backup,
    onClose,
}: {
    backup: Backup;
    onClose: () => void;
}) {
    const [parts, setParts] = useState<Part[]>(
        backup.parts.filter((part) => part !== 'mail'),
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Restore this backup</DialogTitle>
                    <DialogDescription>
                        Choose what to put back. A backup of what is there now
                        is taken first, so the restore can be undone.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...AccountBackupController.restore.form(backup.uuid)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    transform={(data) => ({ ...data, parts })}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <PartChecklist
                                available={backup.parts}
                                selected={parts}
                                onChange={setParts}
                                idPrefix={`restore_${backup.uuid}`}
                            />
                            <InputError message={errors.parts} />

                            <p className="text-sm text-muted-foreground">
                                Files and mail are copied over what is there:
                                something you changed or deleted since the
                                backup goes back to how it was, and something
                                you added since stays. A database is loaded
                                again from its backup. Mail is not ticked by
                                default, because it can bring back messages you
                                deleted.
                            </p>

                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id={`confirm_${backup.uuid}`}
                                    name="confirm"
                                    value="1"
                                />
                                <Label htmlFor={`confirm_${backup.uuid}`}>
                                    I understand this overwrites the current
                                    data
                                </Label>
                            </div>
                            <InputError message={errors.confirm} />
                            <InputError message={errors.backup} />

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    disabled={processing || parts.length === 0}
                                    data-test="confirm-restore-button"
                                >
                                    Restore
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function Index({
    backups,
    nodes,
    keep,
    can_manage = true,
}: Props) {
    const [restoring, setRestoring] = useState<Backup | null>(null);

    const busy =
        backups?.some(
            (backup) =>
                backup.status === 'running' ||
                backup.restore?.status === 'running',
        ) ?? false;

    // While something is running on a server, look again every few seconds.
    useEffect(() => {
        if (!busy) {
            return;
        }

        const timer = setInterval(() => {
            router.reload({ only: ['backups'] });
        }, 4000);

        return () => clearInterval(timer);
    }, [busy]);

    if (backups === null) {
        return (
            <>
                <Head title="Backups" />
                <div className="space-y-6 p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Backups" />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Heading
                    title="Backups"
                    description="Snapshots of your websites, databases and mailboxes, kept on the server and encrypted"
                />

                <p className="text-sm text-muted-foreground">
                    The last {keep} backups per server are kept; older ones are
                    removed. A backup is stored on the same server as your data,
                    so it protects against a mistake, such as a deleted file or
                    a broken update, but not against losing the whole server.
                    There is no download and no automatic schedule yet.
                </p>

                {can_manage && nodes.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium">Back up now</h2>
                        {nodes.map((node) => (
                            <BackUpNow key={node.uuid} node={node} />
                        ))}
                    </section>
                )}

                {nodes.length === 0 && (
                    <p
                        className="text-sm text-muted-foreground"
                        data-test="nothing-to-back-up"
                    >
                        You have no websites, databases or mailboxes to back up
                        yet.
                    </p>
                )}

                <section className="space-y-3">
                    <h2 className="text-sm font-medium">Your backups</h2>

                    {backups.length === 0 ? (
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="no-backups"
                        >
                            No backups yet.
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {backups.map((backup) => (
                                <li
                                    key={backup.uuid}
                                    className="space-y-2 rounded-lg border p-4"
                                    data-test="backup-row"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">
                                                {backup.created_at
                                                    ? new Date(
                                                          backup.created_at,
                                                      ).toLocaleString()
                                                    : ''}{' '}
                                                on {backup.node}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {backup.label
                                                    ? `${backup.label}. `
                                                    : ''}
                                                {backup.parts
                                                    .map(
                                                        (part) =>
                                                            PART_LABELS[part],
                                                    )
                                                    .join(', ')}
                                                {backup.size_bytes !== null
                                                    ? `, ${formatBytes(backup.size_bytes)}`
                                                    : ''}
                                            </p>
                                        </div>

                                        {can_manage && (
                                            <div className="flex gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={
                                                        backup.status !==
                                                            'ready' ||
                                                        backup.restore
                                                            ?.status ===
                                                            'running'
                                                    }
                                                    onClick={() =>
                                                        setRestoring(backup)
                                                    }
                                                    aria-label={`Restore the backup from ${backup.created_at}`}
                                                >
                                                    Restore
                                                </Button>
                                                <Form
                                                    {...AccountBackupController.destroy.form(
                                                        backup.uuid,
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
                                                                processing ||
                                                                backup.status ===
                                                                    'running' ||
                                                                backup.restore
                                                                    ?.status ===
                                                                    'running'
                                                            }
                                                            aria-label={`Delete the backup from ${backup.created_at}`}
                                                        >
                                                            Delete
                                                        </Button>
                                                    )}
                                                </Form>
                                            </div>
                                        )}
                                    </div>

                                    <p
                                        className="text-sm"
                                        aria-live="polite"
                                        data-test="backup-status"
                                    >
                                        {backup.status === 'running' &&
                                            'Backing up… this can take a few minutes.'}
                                        {backup.status === 'ready' && 'Ready.'}
                                        {backup.status === 'failed' && (
                                            <span className="text-red-600 dark:text-red-400">
                                                Failed:{' '}
                                                {backup.error ??
                                                    'the server reported an error.'}
                                            </span>
                                        )}
                                    </p>

                                    {backup.skipped.length > 0 && (
                                        <p className="text-xs text-muted-foreground">
                                            {backup.skipped.length} item
                                            {backup.skipped.length === 1
                                                ? ' was'
                                                : 's were'}{' '}
                                            left out (links that point outside
                                            your folders or files that changed
                                            while backing up).
                                        </p>
                                    )}

                                    {backup.restore && (
                                        <p
                                            className="text-sm"
                                            data-test="restore-status"
                                        >
                                            {backup.restore.status ===
                                                'running' &&
                                                'Restoring… a backup of the current data is taken first.'}
                                            {backup.restore.status ===
                                                'applied' &&
                                                `Restored ${backup.restore.restored
                                                    .map((p) => PART_LABELS[p])
                                                    .join(', ')}${
                                                    backup.restore.at
                                                        ? ` at ${new Date(backup.restore.at).toLocaleString()}`
                                                        : ''
                                                }.`}
                                            {backup.restore.status ===
                                                'failed' && (
                                                <span className="text-red-600 dark:text-red-400">
                                                    Restore failed:{' '}
                                                    {backup.restore.error}
                                                </span>
                                            )}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            {restoring && (
                <RestoreDialog
                    backup={restoring}
                    onClose={() => setRestoring(null)}
                />
            )}
        </>
    );
}
