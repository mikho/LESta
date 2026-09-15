import { Form, Head } from '@inertiajs/react';
import MailDomainController from '@/actions/App/Http/Controllers/Mail/MailDomainController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/mail';

export default function Create() {
    return (
        <>
            <Head title="Add a mail domain" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add a mail domain"
                    description="Provision a new mail domain for this account"
                />

                <Form
                    {...MailDomainController.store.form()}
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
                                    autoFocus
                                    placeholder="example.com"
                                />

                                <InputError message={errors.domain} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="catchall_email">
                                    Catch-all email
                                </Label>

                                <Input
                                    id="catchall_email"
                                    name="catchall_email"
                                    type="email"
                                    placeholder="Optional"
                                />

                                <InputError message={errors.catchall_email} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="antivirus_enabled"
                                    name="antivirus_enabled"
                                    defaultChecked
                                />
                                <Label htmlFor="antivirus_enabled">
                                    Scan incoming mail for viruses
                                </Label>
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="antispam_enabled"
                                    name="antispam_enabled"
                                    defaultChecked
                                />
                                <Label htmlFor="antispam_enabled">
                                    Scan incoming mail for spam
                                </Label>
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="dkim_enabled"
                                    name="dkim_enabled"
                                />
                                <Label htmlFor="dkim_enabled">
                                    Sign outgoing mail with DKIM
                                </Label>
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="create-mail-domain-button"
                                >
                                    Create domain
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
            title: 'Mail',
            href: index(),
        },
        {
            title: 'Add domain',
            href: MailDomainController.create(),
        },
    ],
};
