import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import DnsRecordController from '@/actions/App/Http/Controllers/Dns/DnsRecordController';
import DnsZoneController from '@/actions/App/Http/Controllers/Dns/DnsZoneController';
import { ConfirmActionDialog } from '@/components/confirm-action-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoreActionsMenu } from '@/components/own-resource-list';
import { ProvisioningPanel } from '@/components/provisioning-badge';
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
import { isApplying, usePollWhile } from '@/hooks/use-poll-while';
import dns from '@/routes/dns';
import type { DnsRecord, DnsRecordType, DnsZone } from '@/types';

const recordTypes: { type: DnsRecordType; description: string }[] = [
    { type: 'A', description: 'Points a name at an IPv4 address' },
    { type: 'AAAA', description: 'Points a name at an IPv6 address' },
    { type: 'CNAME', description: 'Makes a name an alias of another name' },
    { type: 'MX', description: 'Says which server receives mail' },
    {
        type: 'TXT',
        description: 'Text, such as SPF or a site verification code',
    },
    { type: 'NS', description: 'Hands a subdomain to other name servers' },
    { type: 'SRV', description: 'Says where a service such as SIP runs' },
    { type: 'PTR', description: 'Points an address back at a name' },
    {
        type: 'CAA',
        description: 'Says which authorities may issue certificates',
    },
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
                    {recordTypes.map(({ type, description }) => (
                        <SelectItem key={type} value={type}>
                            <span className="font-medium">{type}</span>
                            <span className="text-muted-foreground">
                                {description}
                            </span>
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
                <Button
                    variant="outline"
                    size="sm"
                    aria-label={`Edit ${record.name} ${record.type} record`}
                    className="pointer-coarse:h-11"
                >
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

export default function Edit({
    dnsZone,
    nameservers,
}: {
    dnsZone: DnsZone;
    nameservers: string[];
}) {
    const records = dnsZone.records ?? [];

    usePollWhile(isApplying(dnsZone.provisioning_status), ['dnsZone']);

    return (
        <>
            <Head title={dnsZone.domain} />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                <Heading
                    level={1}
                    title={dnsZone.domain}
                    description="DNS zone: the records the name servers give out for this domain"
                />

                <ProvisioningPanel
                    status={dnsZone.provisioning_status}
                    reason={dnsZone.provisioning_error}
                    updatedAt={dnsZone.provisioning_updated_at}
                />

                {nameservers.length > 0 && (
                    <section
                        aria-labelledby="nameservers-heading"
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <Heading
                            variant="small"
                            id="nameservers-heading"
                            title="Name servers"
                            description="Enter these where you registered the domain, so the world asks this server about it. The change can take up to a day to spread."
                        />
                        <ul className="space-y-1 font-mono text-sm">
                            {nameservers.map((nameserver) => (
                                <li key={nameserver}>{nameserver}</li>
                            ))}
                        </ul>
                    </section>
                )}

                <section
                    aria-labelledby="records-heading"
                    className="space-y-4"
                >
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <Heading
                            variant="small"
                            id="records-heading"
                            title="Records"
                            description="What each name in this domain points at."
                        />

                        <AddRecordDialog dnsZone={dnsZone} />
                    </div>

                    {records.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            No records yet. Add an A record for @ to point the
                            domain itself at your site.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">
                                    Records in {dnsZone.domain}
                                </caption>
                                <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="px-4 py-2 font-medium"
                                        >
                                            Name
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-2 font-medium"
                                        >
                                            Type
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden px-4 py-2 font-medium md:table-cell"
                                        >
                                            Points at
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden px-4 py-2 font-medium md:table-cell"
                                        >
                                            Priority
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden px-4 py-2 font-medium md:table-cell"
                                        >
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-2">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {records.map((record) => (
                                        <tr
                                            key={record.uuid}
                                            className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                        >
                                            <th
                                                scope="row"
                                                className="px-4 py-3 text-left font-medium"
                                            >
                                                {record.name}
                                                <span className="mt-1 block max-w-48 truncate font-mono text-xs font-normal text-muted-foreground md:hidden">
                                                    {record.value}
                                                    {record.suspended_at
                                                        ? ' (suspended)'
                                                        : ''}
                                                </span>
                                            </th>
                                            <td className="px-4 py-3">
                                                {record.type}
                                            </td>
                                            <td
                                                className="hidden max-w-xs truncate px-4 py-3 font-mono text-xs text-muted-foreground md:table-cell"
                                                title={record.value}
                                            >
                                                {record.value}
                                            </td>
                                            <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                {record.priority ?? '—'}
                                            </td>
                                            <td className="hidden px-4 py-3 md:table-cell">
                                                {record.suspended_at ? (
                                                    <span className="text-red-700 dark:text-red-400">
                                                        {record.suspension_source ===
                                                        'cascade'
                                                            ? 'Suspended with the zone'
                                                            : 'Suspended'}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Active
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center justify-end gap-1">
                                                    <EditRecordDialog
                                                        dnsZone={dnsZone}
                                                        record={record}
                                                    />
                                                    <MoreActionsMenu
                                                        name={`${record.name} ${record.type}`}
                                                        actions={[
                                                            record.suspended_at
                                                                ? {
                                                                      label: 'Unsuspend',
                                                                      testId: 'toggle-suspend-dns-record-button',
                                                                      confirm: {
                                                                          form: DnsRecordController.unsuspend.form(
                                                                              [
                                                                                  dnsZone,
                                                                                  record,
                                                                              ],
                                                                          ),
                                                                          title: `Unsuspend ${record.name}?`,
                                                                          description:
                                                                              'The record is given out again.',
                                                                          confirmLabel:
                                                                              'Unsuspend',
                                                                          confirmTestId:
                                                                              'confirm-toggle-suspend-dns-record-button',
                                                                      },
                                                                  }
                                                                : {
                                                                      label: 'Suspend',
                                                                      testId: 'toggle-suspend-dns-record-button',
                                                                      confirm: {
                                                                          form: DnsRecordController.suspend.form(
                                                                              [
                                                                                  dnsZone,
                                                                                  record,
                                                                              ],
                                                                          ),
                                                                          title: `Suspend ${record.name}?`,
                                                                          description:
                                                                              'The record stops being given out until you unsuspend it.',
                                                                          confirmLabel:
                                                                              'Suspend',
                                                                          destructive: true,
                                                                          confirmTestId:
                                                                              'confirm-toggle-suspend-dns-record-button',
                                                                      },
                                                                  },
                                                            {
                                                                label: 'Delete',
                                                                testId: 'delete-dns-record-button',
                                                                confirm: {
                                                                    form: DnsRecordController.destroy.form(
                                                                        [
                                                                            dnsZone,
                                                                            record,
                                                                        ],
                                                                    ),
                                                                    title: `Delete ${record.name} ${record.type}?`,
                                                                    description:
                                                                        'This cannot be undone.',
                                                                    confirmLabel:
                                                                        'Delete record',
                                                                    destructive: true,
                                                                    confirmTestId:
                                                                        'confirm-delete-dns-record-button',
                                                                },
                                                            },
                                                        ]}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section
                    aria-labelledby="zone-settings-heading"
                    className="space-y-4 rounded-lg border p-4"
                >
                    <Heading
                        variant="small"
                        id="zone-settings-heading"
                        title="Zone settings"
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
                                    <Label htmlFor="ttl">
                                        Default TTL, in seconds
                                    </Label>

                                    <Input
                                        id="ttl"
                                        name="ttl"
                                        type="number"
                                        min={60}
                                        defaultValue={dnsZone.ttl}
                                        aria-describedby="ttl-help"
                                    />
                                    <p
                                        id="ttl-help"
                                        className="text-sm text-muted-foreground"
                                    >
                                        How long other servers may keep an
                                        answer before asking again. 3600 (an
                                        hour) suits most zones; lower it a day
                                        before you move a site.
                                    </p>

                                    <InputError message={errors.ttl} />
                                </div>

                                <Button
                                    disabled={processing}
                                    data-test="update-dns-zone-button"
                                >
                                    Save
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section
                    aria-labelledby="danger-heading"
                    className="space-y-4 rounded-lg border border-red-200 p-4 dark:border-red-900"
                >
                    <Heading
                        variant="small"
                        id="danger-heading"
                        title="Suspend or delete"
                        description="Suspending stops this server answering for the domain until you unsuspend it, so its site and mail may stop being found. Deleting removes the zone and all its records for good."
                    />

                    <div className="flex flex-wrap gap-2">
                        {dnsZone.suspended_at ? (
                            <ConfirmActionDialog
                                form={DnsZoneController.unsuspend.form(dnsZone)}
                                title={`Unsuspend ${dnsZone.domain}?`}
                                description="The server answers DNS for this domain again."
                                confirmLabel="Unsuspend"
                                confirmTestId="confirm-toggle-suspend-dns-zone-button"
                                trigger={
                                    <Button data-test="toggle-suspend-dns-zone-button">
                                        Unsuspend zone
                                    </Button>
                                }
                            />
                        ) : (
                            <ConfirmActionDialog
                                form={DnsZoneController.suspend.form(dnsZone)}
                                title={`Suspend ${dnsZone.domain}?`}
                                description="The server stops answering DNS for this domain until you unsuspend it."
                                confirmLabel="Suspend"
                                destructive
                                confirmTestId="confirm-toggle-suspend-dns-zone-button"
                                trigger={
                                    <Button
                                        variant="outline"
                                        data-test="toggle-suspend-dns-zone-button"
                                    >
                                        Suspend zone
                                    </Button>
                                }
                            />
                        )}

                        <ConfirmActionDialog
                            form={DnsZoneController.destroy.form(dnsZone)}
                            title={`Delete ${dnsZone.domain}?`}
                            description="This cannot be undone. The zone and all its records are removed from the server."
                            confirmLabel="Delete zone"
                            destructive
                            confirmTestId="confirm-delete-dns-zone-button"
                            trigger={
                                <Button
                                    variant="destructive"
                                    data-test="delete-dns-zone-button"
                                >
                                    Delete zone
                                </Button>
                            }
                        />
                    </div>
                </section>
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
