import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import DnsRecordController from '@/actions/App/Http/Controllers/Dns/DnsRecordController';
import DnsZoneController from '@/actions/App/Http/Controllers/Dns/DnsZoneController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
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
import { Textarea } from '@/components/ui/textarea';
import dns from '@/routes/dns';
import type { DnsRecord, DnsRecordType, DnsZone } from '@/types';

const recordTypes: DnsRecordType[] = [
    'A',
    'AAAA',
    'NS',
    'CNAME',
    'MX',
    'TXT',
    'SRV',
    'PTR',
    'CAA',
];

function RecordTypeSelect({
    value,
    onValueChange,
    error,
}: {
    value: DnsRecordType;
    onValueChange: (value: DnsRecordType) => void;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor="type">Type</Label>

            <Select
                name="type"
                value={value}
                onValueChange={(v) => onValueChange(v as DnsRecordType)}
            >
                <SelectTrigger id="type">
                    <SelectValue placeholder="Select a record type" />
                </SelectTrigger>
                <SelectContent>
                    {recordTypes.map((type) => (
                        <SelectItem key={type} value={type}>
                            {type}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <InputError message={error} />
        </div>
    );
}

function AddRecordDialog({ dnsZone }: { dnsZone: DnsZone }) {
    const [type, setType] = useState<DnsRecordType>('A');
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" data-test="add-dns-record-button">
                    Add record
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Add a DNS record</DialogTitle>
                <DialogDescription>
                    Add a new record to {dnsZone.domain}.
                </DialogDescription>

                <Form
                    {...DnsRecordController.store.form(dnsZone)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder="www or @ for the zone apex"
                                />

                                <InputError message={errors.name} />
                            </div>

                            <RecordTypeSelect
                                value={type}
                                onValueChange={setType}
                                error={errors.type}
                            />

                            {(type === 'MX' || type === 'SRV') && (
                                <div className="grid gap-2">
                                    <Label htmlFor="priority">Priority</Label>

                                    <Input
                                        id="priority"
                                        name="priority"
                                        type="number"
                                    />

                                    <InputError message={errors.priority} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="value">Value</Label>

                                <Textarea id="value" name="value" rows={2} />

                                <InputError message={errors.value} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    disabled={processing}
                                    data-test="create-dns-record-button"
                                >
                                    Add record
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function EditRecordDialog({
    dnsZone,
    record,
}: {
    dnsZone: DnsZone;
    record: DnsRecord;
}) {
    const [type, setType] = useState<DnsRecordType>(record.type);
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Edit
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Edit {record.name}</DialogTitle>
                <DialogDescription>
                    Update this record on {dnsZone.domain}.
                </DialogDescription>

                <Form
                    {...DnsRecordController.update.form([dnsZone, record])}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="edit-name">Name</Label>

                                <Input
                                    id="edit-name"
                                    name="name"
                                    required
                                    defaultValue={record.name}
                                />

                                <InputError message={errors.name} />
                            </div>

                            <RecordTypeSelect
                                value={type}
                                onValueChange={setType}
                                error={errors.type}
                            />

                            {(type === 'MX' || type === 'SRV') && (
                                <div className="grid gap-2">
                                    <Label htmlFor="edit-priority">
                                        Priority
                                    </Label>

                                    <Input
                                        id="edit-priority"
                                        name="priority"
                                        type="number"
                                        defaultValue={
                                            record.priority ?? undefined
                                        }
                                    />

                                    <InputError message={errors.priority} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="edit-value">Value</Label>

                                <Textarea
                                    id="edit-value"
                                    name="value"
                                    rows={2}
                                    defaultValue={record.value}
                                />

                                <InputError message={errors.value} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    disabled={processing}
                                    data-test="update-dns-record-button"
                                >
                                    Save
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ToggleSuspendRecordDialog({
    dnsZone,
    record,
}: {
    dnsZone: DnsZone;
    record: DnsRecord;
}) {
    const [open, setOpen] = useState(false);

    if (record.suspended_at) {
        return (
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="toggle-suspend-dns-record-button"
                    >
                        Unsuspend
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Unsuspend {record.name}?</DialogTitle>
                    <DialogDescription>
                        The record will resume resolving.
                    </DialogDescription>

                    <Form
                        {...DnsRecordController.unsuspend.form([
                            dnsZone,
                            record,
                        ])}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
                    >
                        {({ processing }) => (
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button disabled={processing} asChild>
                                    <button
                                        type="submit"
                                        data-test="confirm-toggle-suspend-dns-record-button"
                                    >
                                        Unsuspend
                                    </button>
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    data-test="toggle-suspend-dns-record-button"
                >
                    Suspend
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Suspend {record.name}?</DialogTitle>
                <DialogDescription>
                    The record will stop resolving until it is unsuspended.
                </DialogDescription>

                <Form
                    {...DnsRecordController.suspend.form([dnsZone, record])}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>

                            <Button
                                variant="destructive"
                                disabled={processing}
                                asChild
                            >
                                <button
                                    type="submit"
                                    data-test="confirm-toggle-suspend-dns-record-button"
                                >
                                    Suspend
                                </button>
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function Edit({ dnsZone }: { dnsZone: DnsZone }) {
    const records = dnsZone.records ?? [];

    return (
        <>
            <Head title={`Edit ${dnsZone.domain}`} />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                <Heading
                    title="Edit DNS zone"
                    description="Update this zone's configuration"
                />

                <Form
                    {...DnsZoneController.update.form(dnsZone)}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="domain">Domain</Label>

                                <Input
                                    id="domain"
                                    name="domain"
                                    required
                                    defaultValue={dnsZone.domain}
                                />

                                <InputError message={errors.domain} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="ttl">TTL (seconds)</Label>

                                <Input
                                    id="ttl"
                                    name="ttl"
                                    type="number"
                                    defaultValue={dnsZone.ttl}
                                />

                                <InputError message={errors.ttl} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-dns-zone-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-y-4">
                    <div className="flex items-center justify-between gap-4">
                        <Heading variant="small" title="DNS records" />

                        <AddRecordDialog dnsZone={dnsZone} />
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Type
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Priority
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Value
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Suspension
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {records.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            No records yet.
                                        </td>
                                    </tr>
                                )}

                                {records.map((record) => (
                                    <tr
                                        key={record.uuid}
                                        className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td className="px-4 py-2 font-medium">
                                            {record.name}
                                        </td>
                                        <td className="px-4 py-2">
                                            {record.type}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {record.priority ?? '—'}
                                        </td>
                                        <td className="max-w-xs truncate px-4 py-2 text-muted-foreground">
                                            {record.value}
                                        </td>
                                        <td className="px-4 py-2">
                                            {record.suspended_at ? (
                                                <span className="text-red-600 dark:text-red-400">
                                                    Suspended
                                                    {record.suspension_source ===
                                                    'cascade'
                                                        ? ' (zone)'
                                                        : ''}
                                                </span>
                                            ) : (
                                                <span className="text-green-600 dark:text-green-400">
                                                    Active
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <EditRecordDialog
                                                    dnsZone={dnsZone}
                                                    record={record}
                                                />

                                                <ToggleSuspendRecordDialog
                                                    dnsZone={dnsZone}
                                                    record={record}
                                                />

                                                <Dialog>
                                                    <DialogTrigger asChild>
                                                        <Button
                                                            variant="destructive"
                                                            size="sm"
                                                            data-test="delete-dns-record-button"
                                                        >
                                                            Delete
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent>
                                                        <DialogTitle>
                                                            Delete {record.name}
                                                            ?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            This cannot be
                                                            undone.
                                                        </DialogDescription>

                                                        <Form
                                                            {...DnsRecordController.destroy.form(
                                                                [
                                                                    dnsZone,
                                                                    record,
                                                                ],
                                                            )}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <DialogFooter className="gap-2">
                                                                    <DialogClose
                                                                        asChild
                                                                    >
                                                                        <Button variant="secondary">
                                                                            Cancel
                                                                        </Button>
                                                                    </DialogClose>

                                                                    <Button
                                                                        variant="destructive"
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                        asChild
                                                                    >
                                                                        <button
                                                                            type="submit"
                                                                            data-test="confirm-delete-dns-record-button"
                                                                        >
                                                                            Delete
                                                                        </button>
                                                                    </Button>
                                                                </DialogFooter>
                                                            )}
                                                        </Form>
                                                    </DialogContent>
                                                </Dialog>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title={
                            dnsZone.suspended_at
                                ? 'Unsuspend zone'
                                : 'Suspend zone'
                        }
                        description={
                            dnsZone.suspended_at
                                ? 'Resume serving DNS queries for this zone.'
                                : 'Stop serving DNS queries for this zone until it is unsuspended.'
                        }
                    />

                    <Form
                        {...(dnsZone.suspended_at
                            ? DnsZoneController.unsuspend.form(dnsZone)
                            : DnsZoneController.suspend.form(dnsZone))}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant={
                                    dnsZone.suspended_at ? 'default' : 'outline'
                                }
                                disabled={processing}
                                data-test="toggle-suspend-dns-zone-button"
                            >
                                {dnsZone.suspended_at ? 'Unsuspend' : 'Suspend'}
                            </Button>
                        )}
                    </Form>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete zone"
                        description="Delete this zone and all of its records"
                    />
                    <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                        <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                            <p className="font-medium">Warning</p>
                            <p className="text-sm">
                                Please proceed with caution, this cannot be
                                undone.
                            </p>
                        </div>

                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    data-test="delete-dns-zone-button"
                                >
                                    Delete zone
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete{' '}
                                    {dnsZone.domain}?
                                </DialogTitle>
                                <DialogDescription>
                                    Once this zone is deleted, all of its
                                    records and provisioning state will also be
                                    permanently deleted.
                                </DialogDescription>

                                <Form
                                    {...DnsZoneController.destroy.form(dnsZone)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button variant="secondary">
                                                    Cancel
                                                </Button>
                                            </DialogClose>

                                            <Button
                                                variant="destructive"
                                                disabled={processing}
                                                asChild
                                            >
                                                <button
                                                    type="submit"
                                                    data-test="confirm-delete-dns-zone-button"
                                                >
                                                    Delete zone
                                                </button>
                                            </Button>
                                        </DialogFooter>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>
            </div>
        </>
    );
}

Edit.layout = {
    breadcrumbs: [
        {
            title: 'DNS',
            href: dns.index(),
        },
    ],
};
