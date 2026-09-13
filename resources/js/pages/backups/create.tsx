import { Form, Head } from '@inertiajs/react';
import BackupController from '@/actions/App/Http/Controllers/Backups/BackupController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/backups';
import type { BackupCapableNode } from '@/types';

export default function Create({ nodes }: { nodes: BackupCapableNode[] }) {
    return (
        <>
            <Head title="New backup" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="New backup"
                    description="Snapshot every account hosted on a node right now"
                />

                <Form {...BackupController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="node">Node</Label>

                                {nodes.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No node has an active
                                        backup.encrypted-artifacts.v1 capability
                                        yet.
                                    </p>
                                ) : (
                                    <Select name="node">
                                        <SelectTrigger id="node">
                                            <SelectValue placeholder="Select a node" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {nodes.map((node) => (
                                                <SelectItem
                                                    key={node.uuid}
                                                    value={node.uuid}
                                                >
                                                    {node.name} ({node.hostname}
                                                    )
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}

                                <InputError message={errors.node} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="label">Label (optional)</Label>

                                <Input
                                    id="label"
                                    name="label"
                                    placeholder="nightly"
                                />

                                <InputError message={errors.label} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing || nodes.length === 0}
                                    data-test="create-backup-button"
                                >
                                    Create backup
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Create.layout = {
    breadcrumbs: [
        {
            title: 'Backups',
            href: index(),
        },
        {
            title: 'New backup',
            href: BackupController.create(),
        },
    ],
};
