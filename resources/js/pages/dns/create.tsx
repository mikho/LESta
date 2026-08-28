import { Form, Head } from '@inertiajs/react';
import DnsZoneController from '@/actions/App/Http/Controllers/Dns/DnsZoneController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/dns';

export default function Create() {
    return (
        <>
            <Head title="Add a DNS zone" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add a DNS zone"
                    description="Provision a new DNS zone for this account"
                />

                <Form {...DnsZoneController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="domain">Domain</Label>

                                <Input
                                    id="domain"
                                    name="domain"
                                    required
                                    autoFocus
                                    placeholder="example.com"
                                />

                                <InputError message={errors.domain} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="ttl">TTL (seconds)</Label>

                                <Input
                                    id="ttl"
                                    name="ttl"
                                    type="number"
                                    placeholder="14400"
                                />

                                <InputError message={errors.ttl} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="create-dns-zone-button"
                                >
                                    Create zone
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
            title: 'DNS',
            href: index(),
        },
        {
            title: 'Add zone',
            href: DnsZoneController.create(),
        },
    ],
};
