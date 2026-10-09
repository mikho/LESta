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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Part = 'files' | 'databases' | 'mail';

type Backup = {
    uuid: string;
    label: string | null;
    kind: 'manual' | 'scheduled' | 'before_restore';
    node: string;
    status: 'running' | 'ready' | 'failed';
    error: string | null;
    parts: Part[];
    size_bytes: number | null;
    skipped: string[];
    download: {
        status: 'pending' | 'ready' | 'failed';
        size_bytes: number;
        expires_at: string;
        error: string | null;
        url: string | null;
    } | null;
    remote: {
        status: 'copying' | 'copied' | 'failed';
        key: string | null;
        at: string | null;
        error: string | null;
    } | null;
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

type Schedule = {
    frequency: 'off' | 'daily' | 'weekly';
    parts: Part[];
    next_run_at: string | null;
    last_run_at: string | null;
    last_message: string | null;
};

type Destination = {
    enabled: boolean;
    endpoint: string;
    region: string;
    bucket: string;
    prefix: string;
    has_keys: boolean;
};

type Props = {
    backups: Backup[] | null;
    destination: Destination | null;
    nodes: NodeInfo[];
    keep: { manual: number; scheduled: number; before_restore: number };
    schedule: Schedule | null;
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

function ScheduleSection({ schedule }: { schedule: Schedule }) {
    const [frequency, setFrequency] = useState(schedule.frequency);
    const [parts, setParts] = useState<Part[]>(schedule.parts);

    return (
        <Form
            {...AccountBackupController.updateSchedule.form()}
            options={{ preserveScroll: true }}
            transform={(data) => ({ ...data, frequency, parts })}
            className="space-y-3 rounded-lg border p-4"
            data-test="schedule-form"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="schedule_frequency">
                            Automatic backups
                        </Label>
                        <Select
                            value={frequency}
                            onValueChange={(value) =>
                                setFrequency(value as Schedule['frequency'])
                            }
                        >
                            <SelectTrigger id="schedule_frequency">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="off">Off</SelectItem>
                                <SelectItem value="daily">
                                    Every night
                                </SelectItem>
                                <SelectItem value="weekly">
                                    Once a week
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.frequency} />
                    </div>

                    {frequency !== 'off' && (
                        <>
                            <PartChecklist
                                available={['files', 'databases', 'mail']}
                                selected={parts}
                                onChange={setParts}
                                idPrefix="schedule"
                            />
                            <InputError message={errors.parts} />
                        </>
                    )}

                    <p className="text-sm text-muted-foreground">
                        Runs at night (between 01:00 and 04:00 UTC, at a time
                        picked for your account). Automatic backups are kept
                        separately from the ones you make yourself.
                        {schedule.next_run_at && schedule.frequency !== 'off'
                            ? ` Next run: ${new Date(schedule.next_run_at).toLocaleString()}.`
                            : ''}
                    </p>

                    {schedule.last_message && (
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="schedule-last-message"
                        >
                            Last run
                            {schedule.last_run_at
                                ? ` ${new Date(schedule.last_run_at).toLocaleString()}`
                                : ''}
                            : {schedule.last_message}
                        </p>
                    )}

                    <Button
                        disabled={processing}
                        data-test="save-schedule-button"
                    >
                        Save
                    </Button>
                </>
            )}
        </Form>
    );
}

function StorageSection({ destination }: { destination: Destination | null }) {
    const [enabled, setEnabled] = useState(destination?.enabled ?? true);

    return (
        <section
            className="space-y-3 rounded-lg border p-4"
            data-test="storage-section"
        >
            <h2 className="text-sm font-medium">Copy to your own storage</h2>
            <p className="text-sm text-muted-foreground">
                After each backup, a copy is sent to a bucket you own on any
                S3-compatible service (Amazon S3, Backblaze B2, Wasabi,
                Cloudflare R2, MinIO and others), as a standard .tar.gz you can
                open anywhere. That keeps your data safe if this server is lost.
                Copies are never deleted from here: set a lifecycle rule on your
                bucket to remove old ones. Use a key that can only write to this
                bucket.
            </p>

            <Form
                {...AccountBackupController.updateDestination.form()}
                options={{ preserveScroll: true }}
                transform={(data) => ({
                    ...data,
                    enabled: enabled ? '1' : '0',
                })}
                className="space-y-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="storage_endpoint">
                                    Service address
                                </Label>
                                <Input
                                    id="storage_endpoint"
                                    name="endpoint"
                                    defaultValue={destination?.endpoint}
                                    placeholder="https://s3.eu-west-1.amazonaws.com"
                                    autoComplete="off"
                                    required
                                />
                                <InputError message={errors.endpoint} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="storage_region">Region</Label>
                                <Input
                                    id="storage_region"
                                    name="region"
                                    defaultValue={destination?.region}
                                    placeholder="eu-west-1"
                                    autoComplete="off"
                                    required
                                />
                                <InputError message={errors.region} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="storage_bucket">Bucket</Label>
                                <Input
                                    id="storage_bucket"
                                    name="bucket"
                                    defaultValue={destination?.bucket}
                                    autoComplete="off"
                                    required
                                />
                                <InputError message={errors.bucket} />
                            </div>
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="storage_prefix">
                                    Folder in the bucket (optional)
                                </Label>
                                <Input
                                    id="storage_prefix"
                                    name="prefix"
                                    defaultValue={destination?.prefix}
                                    placeholder="lesta-backups/"
                                    autoComplete="off"
                                />
                                <InputError message={errors.prefix} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="storage_access_key">
                                    Access key
                                </Label>
                                <Input
                                    id="storage_access_key"
                                    name="access_key"
                                    placeholder={
                                        destination?.has_keys
                                            ? 'Saved. Leave empty to keep it.'
                                            : ''
                                    }
                                    autoComplete="off"
                                    required={!destination?.has_keys}
                                />
                                <InputError message={errors.access_key} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="storage_secret_key">
                                    Secret key
                                </Label>
                                <Input
                                    id="storage_secret_key"
                                    name="secret_key"
                                    type="password"
                                    placeholder={
                                        destination?.has_keys
                                            ? 'Saved. Leave empty to keep it.'
                                            : ''
                                    }
                                    autoComplete="new-password"
                                    required={!destination?.has_keys}
                                />
                                <InputError message={errors.secret_key} />
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="storage_enabled"
                                checked={enabled}
                                onCheckedChange={(checked) =>
                                    setEnabled(checked === true)
                                }
                            />
                            <Label htmlFor="storage_enabled">
                                Copy new backups here automatically
                            </Label>
                        </div>

                        <div className="flex gap-2">
                            <Button
                                disabled={processing}
                                data-test="save-storage-button"
                            >
                                Save
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            {destination && (
                <Form
                    {...AccountBackupController.destroyDestination.form()}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            Remove my storage
                        </Button>
                    )}
                </Form>
            )}
        </section>
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
    destination,
    nodes,
    keep,
    schedule,
    can_manage = true,
}: Props) {
    const [restoring, setRestoring] = useState<Backup | null>(null);

    const busy =
        backups?.some(
            (backup) =>
                backup.status === 'running' ||
                backup.restore?.status === 'running' ||
                backup.download?.status === 'pending' ||
                backup.remote?.status === 'copying',
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
                    The last {keep.manual} backups you make, {keep.scheduled}{' '}
                    automatic ones and {keep.before_restore} safety backups
                    taken before a restore are kept per server; older ones are
                    removed. A backup is stored on the same server as your data,
                    so it protects against a mistake, such as a deleted file or
                    a broken update, but not against losing the whole server.
                </p>

                {can_manage && nodes.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium">Back up now</h2>
                        {nodes.map((node) => (
                            <BackUpNow key={node.uuid} node={node} />
                        ))}
                    </section>
                )}

                {can_manage && schedule && nodes.length > 0 && (
                    <ScheduleSection schedule={schedule} />
                )}

                {can_manage && nodes.length > 0 && (
                    <StorageSection destination={destination} />
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
                                                {backup.kind === 'scheduled'
                                                    ? 'Automatic. '
                                                    : ''}
                                                {backup.label &&
                                                backup.kind !== 'scheduled'
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
                                                {destination?.enabled &&
                                                    backup.status === 'ready' &&
                                                    backup.remote === null && (
                                                        <Form
                                                            {...AccountBackupController.copy.form(
                                                                backup.uuid,
                                                            )}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                    aria-label={`Copy the backup from ${backup.created_at} to your storage`}
                                                                >
                                                                    Copy to my
                                                                    storage
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    )}
                                                <Form
                                                    {...AccountBackupController.prepareDownload.form(
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
                                                                backup.status !==
                                                                    'ready' ||
                                                                backup.download
                                                                    ?.status ===
                                                                    'pending'
                                                            }
                                                            aria-label={`Prepare a download of the backup from ${backup.created_at}`}
                                                        >
                                                            Download
                                                        </Button>
                                                    )}
                                                </Form>
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

                                    {backup.remote && (
                                        <p
                                            className="text-sm"
                                            data-test="remote-status"
                                            aria-live="polite"
                                        >
                                            {backup.remote.status ===
                                                'copying' &&
                                                'Copying to your storage…'}
                                            {backup.remote.status ===
                                                'copied' &&
                                                `Copied to your storage${backup.remote.at ? ` at ${new Date(backup.remote.at).toLocaleString()}` : ''}${backup.remote.key ? ` as ${backup.remote.key}` : ''}.`}
                                            {backup.remote.status ===
                                                'failed' && (
                                                <span className="text-red-600 dark:text-red-400">
                                                    The copy to your storage
                                                    failed:{' '}
                                                    {backup.remote.error}
                                                </span>
                                            )}
                                            {backup.remote.status ===
                                                'failed' &&
                                                can_manage && (
                                                    <Form
                                                        {...AccountBackupController.copy.form(
                                                            backup.uuid,
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                        className="mt-1"
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Try again
                                                            </Button>
                                                        )}
                                                    </Form>
                                                )}
                                        </p>
                                    )}

                                    {backup.download && (
                                        <p
                                            className="text-sm"
                                            data-test="download-status"
                                            aria-live="polite"
                                        >
                                            {backup.download.status ===
                                                'pending' &&
                                                'Preparing the download… this can take a few minutes for a large backup.'}
                                            {backup.download.status ===
                                                'ready' &&
                                                backup.download.url && (
                                                    <>
                                                        <a
                                                            href={
                                                                backup.download
                                                                    .url
                                                            }
                                                            className="font-medium underline"
                                                            download
                                                        >
                                                            Download the backup
                                                            (
                                                            {formatBytes(
                                                                backup.download
                                                                    .size_bytes,
                                                            )}
                                                            )
                                                        </a>
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            A standard .tar.gz
                                                            with your files,
                                                            database dumps and
                                                            mailboxes. Available
                                                            until{' '}
                                                            {new Date(
                                                                backup.download
                                                                    .expires_at,
                                                            ).toLocaleTimeString()}
                                                            .
                                                        </span>
                                                    </>
                                                )}
                                            {backup.download.status ===
                                                'failed' && (
                                                <span className="text-red-600 dark:text-red-400">
                                                    The download could not be
                                                    prepared:{' '}
                                                    {backup.download.error}
                                                </span>
                                            )}
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
