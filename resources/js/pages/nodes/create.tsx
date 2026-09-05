import { Form, Head } from '@inertiajs/react';
import NodeController from '@/actions/App/Http/Controllers/Nodes/NodeController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/nodes';

export default function Create() {
    return (
        <>
            <Head title="Add a node" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add a node"
                    description="Register a new infrastructure node"
                />

                <Form {...NodeController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder="node-01"
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="hostname">Hostname</Label>

                                <Input
                                    id="hostname"
                                    name="hostname"
                                    required
                                    placeholder="node-01.example.net"
                                />

                                <InputError message={errors.hostname} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="create-node-button"
                                >
                                    Create node
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
            title: 'Nodes',
            href: index(),
        },
        {
            title: 'Add node',
            href: NodeController.create(),
        },
    ],
};
