import { Form, Head, Link } from '@inertiajs/react';
import MailDomainController from '@/actions/App/Http/Controllers/Mail/MailDomainController';
import { FormCheckbox } from '@/components/form-checkbox';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ScannerSwitch } from '@/components/scanner-switch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/mail';

export default function Create({
    antivirusAvailable,
    antispamAvailable,
}: {
    antivirusAvailable: boolean;
    antispamAvailable: boolean;
}) {
    return (
        <>
            <Head title="Add mail domain" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add mail domain"
                    description="Receive mail for a domain you own. You add mailboxes next."
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

                            <ScannerSwitch
                                name="antivirus_enabled"
                                label="Scan incoming mail for viruses"
                                unavailable="Virus scanning is not installed on this server."
                                available={antivirusAvailable}
                                defaultChecked
                            />

                            <ScannerSwitch
                                name="antispam_enabled"
                                label="Scan incoming mail for spam"
                                unavailable="Spam checking is not installed on this server."
                                available={antispamAvailable}
                                defaultChecked
                            />

                            <div className="flex items-center space-x-3">
                                <FormCheckbox
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
                                    Add mail domain
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
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
