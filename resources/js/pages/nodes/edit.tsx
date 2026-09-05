import { Form, Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import NodeCapabilityController from '@/actions/App/Http/Controllers/Nodes/NodeCapabilityController';
import NodeController from '@/actions/App/Http/Controllers/Nodes/NodeController';
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
import nodes from '@/routes/nodes';
import type { Node, NodeCapability, NodeProvisioningOperation } from '@/types';

const capabilityOptions = [
    'web.nginx.v1',
    'dns.bind9.v1',
    'web.apache.v1',
    'tls.acme.v1',
    'database.tenant.v1',
    'scheduler.account-cron.v1',
] as const;

const operationStatusBadgeClasses: Record<string, string> = {
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
};

function OperationRow({
    operation,
}: {
    operation: NodeProvisioningOperation;
}) {
    return (
        <details className="rounded-lg border p-3">
            <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2">
                <span className="text-sm">
                    {operation.capability} · {operation.operation}
                </span>
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${operationStatusBadgeClasses[operation.status] ?? ''}`}
                >
                    {operation.status.replace('_', ' ')}
                </span>
            </summary>

            <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                <p>
                    Issued {new Date(operation.issued_at).toLocaleString()}
                </p>
                {operation.completed_at && (
                    <p>
                        Completed{' '}
                        {new Date(operation.completed_at).toLocaleString()}
                    </p>
                )}
            </div>
        </details>
    );
}

function IssueEnrollmentTokenDialog({ node }: { node: Node }) {
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [token, setToken] = useState<string | null>(null);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const issuedToken = flash?.enrollmentToken as string | undefined;

            if (issuedToken) {
                setToken(issuedToken);
            }
        });
    }, []);

    return (
        <>
            <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                <DialogTrigger asChild>
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="issue-enrollment-token-button"
                    >
                        Issue enrollment token
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Issue a new enrollment token?</DialogTitle>
                    <DialogDescription>
                        Any previously issued, unused token for {node.name}{' '}
                        will be invalidated. The new token is shown once and
                        cannot be recovered afterwards.
                    </DialogDescription>

                    <Form
                        {...NodeController.issueEnrollmentToken.form(node)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setConfirmOpen(false)}
                    >
                        {({ processing }) => (
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>

                                <Button disabled={processing} asChild>
                                    <button
                                        type="submit"
                                        data-test="confirm-issue-enrollment-token-button"
                                    >
                                        Issue token
                                    </button>
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={token !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setToken(null);
                    }
                }}
            >
                <DialogContent data-test="enrollment-token-dialog">
                    <DialogTitle>Enrollment token</DialogTitle>
                    <DialogDescription>
                        Copy this token now; it will not be shown again.
                    </DialogDescription>

                    <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs break-all whitespace-pre-wrap">
                        {token}
                    </pre>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button data-test="dismiss-enrollment-token-button">
                                Done
                            </Button>
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function CapabilityRow({
    node,
    capability,
}: {
    node: Node;
    capability: NodeCapability;
}) {
    return (
        <tr className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
            <td className="px-4 py-2 font-medium">{capability.capability}</td>
            <td className="px-4 py-2">
                {capability.suspended_at ? (
                    <span className="text-red-600 dark:text-red-400">
                        Suspended
                        {capability.suspension_source === 'cascade'
                            ? ' (node)'
                            : ''}
                    </span>
                ) : (
                    <span className="text-green-600 dark:text-green-400">
                        Active
                    </span>
                )}
            </td>
            <td className="px-4 py-2">
                <Form
                    {...(capability.suspended_at
                        ? NodeCapabilityController.unsuspend.form([
                              node,
                              capability,
                          ])
                        : NodeCapabilityController.suspend.form([
                              node,
                              capability,
                          ]))}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            {capability.suspended_at
                                ? 'Unsuspend'
                                : 'Suspend'}
                        </Button>
                    )}
                </Form>
            </td>
        </tr>
    );
}

function AddCapabilityForm({ node }: { node: Node }) {
    const [capability, setCapability] = useState<string>(
        capabilityOptions[0],
    );

    return (
        <Form
            {...NodeCapabilityController.store.form(node)}
            options={{ preserveScroll: true }}
            className="flex flex-wrap items-end gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="capability">Capability</Label>

                        <Select
                            name="capability"
                            value={capability}
                            onValueChange={setCapability}
                        >
                            <SelectTrigger
                                id="capability"
                                className="w-64"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {capabilityOptions.map((option) => (
                                    <SelectItem key={option} value={option}>
                                        {option}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <InputError message={errors.capability} />
                    </div>

                    <Button disabled={processing} data-test="add-capability-button">
                        Add capability
                    </Button>
                </>
            )}
        </Form>
    );
}

export default function Edit({ node }: { node: Node }) {
    const capabilities = node.capabilities ?? [];
    const operations = node.recent_operations ?? [];

    return (
        <>
            <Head title={`Manage ${node.name}`} />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                <Heading
                    title="Manage node"
                    description="Update this node's configuration and lifecycle"
                />

                <Form
                    {...NodeController.update.form(node)}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    defaultValue={node.name}
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="hostname">Hostname</Label>

                                <Input
                                    id="hostname"
                                    name="hostname"
                                    required
                                    defaultValue={node.hostname}
                                />

                                <InputError message={errors.hostname} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-node-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading variant="small" title="Enrollment" />

                    <dl className="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt className="text-muted-foreground">Status</dt>
                            <dd>{node.enrollment_status}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Last seen
                            </dt>
                            <dd>
                                {node.last_seen_at
                                    ? new Date(
                                          node.last_seen_at,
                                      ).toLocaleString()
                                    : 'never'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Protocol version
                            </dt>
                            <dd>{node.protocol_version ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Agent version
                            </dt>
                            <dd>{node.agent_version ?? '—'}</dd>
                        </div>
                    </dl>

                    <IssueEnrollmentTokenDialog node={node} />
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <div className="flex items-center justify-between gap-4">
                        <Heading variant="small" title="Capabilities" />
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Capability
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {capabilities.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            No capabilities yet.
                                        </td>
                                    </tr>
                                )}

                                {capabilities.map((capability) => (
                                    <CapabilityRow
                                        key={capability.id}
                                        node={node}
                                        capability={capability}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <AddCapabilityForm node={node} />
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="Recent provisioning operations"
                        description="The most recent operations dispatched to this node"
                    />

                    {operations.length > 0 ? (
                        <div className="space-y-2">
                            {operations.map((operation, index) => (
                                <OperationRow
                                    key={`${operation.capability}-${operation.issued_at}-${index}`}
                                    operation={operation}
                                />
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No provisioning operations yet.
                        </p>
                    )}
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title={
                            node.suspended_at
                                ? 'Unsuspend node'
                                : 'Suspend node'
                        }
                        description={
                            node.suspended_at
                                ? 'Resume this node and its non-manually-suspended capabilities.'
                                : 'Stop this node and cascade-suspend its active capabilities until it is unsuspended.'
                        }
                    />

                    <Form
                        {...(node.suspended_at
                            ? NodeController.unsuspend.form(node)
                            : NodeController.suspend.form(node))}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant={
                                    node.suspended_at ? 'default' : 'outline'
                                }
                                disabled={processing}
                                data-test="toggle-suspend-node-button"
                            >
                                {node.suspended_at ? 'Unsuspend' : 'Suspend'}
                            </Button>
                        )}
                    </Form>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete node"
                        description="Delete this node"
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
                                    data-test="delete-node-button"
                                >
                                    Delete node
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete{' '}
                                    {node.name}?
                                </DialogTitle>
                                <DialogDescription>
                                    A node with dependent resources (domains,
                                    DNS zones, databases, cron jobs, or
                                    provisioning history) cannot be deleted.
                                </DialogDescription>

                                <Form
                                    {...NodeController.destroy.form(node)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <InputError
                                                message={errors.node}
                                            />

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
                                                        data-test="confirm-delete-node-button"
                                                    >
                                                        Delete node
                                                    </button>
                                                </Button>
                                            </DialogFooter>
                                        </>
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
            title: 'Nodes',
            href: nodes.index(),
        },
    ],
};
