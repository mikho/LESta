import { Form, Head } from '@inertiajs/react';
import WebDomainController from '@/actions/App/Http/Controllers/Domains/WebDomainController';
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
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/domains';

export default function Create() {
    return (
        <>
            <Head title="Add a domain" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add a domain"
                    description="Provision a new web domain for this account"
                />

                <Form
                    {...WebDomainController.store.form()}
                    transform={(data) => ({
                        ...data,
                        aliases:
                            typeof data.aliases === 'string'
                                ? data.aliases
                                      .split('\n')
                                      .map((alias: string) => alias.trim())
                                      .filter((alias: string) => alias !== '')
                                : [],
                    })}
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
                                <Label htmlFor="aliases">
                                    Aliases (one per line)
                                </Label>

                                <Textarea
                                    id="aliases"
                                    name="aliases"
                                    rows={4}
                                    placeholder={
                                        'www.example.com\nshop.example.com'
                                    }
                                />

                                <InputError message={errors.aliases} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="web_template">Template</Label>

                                <Input
                                    id="web_template"
                                    name="web_template"
                                    defaultValue="default"
                                />

                                <InputError message={errors.web_template} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="web_server">Web server</Label>

                                <Select name="web_server" defaultValue="nginx">
                                    <SelectTrigger id="web_server">
                                        <SelectValue placeholder="Select a web server" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="nginx">
                                            nginx
                                        </SelectItem>
                                        <SelectItem value="apache">
                                            Apache
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <p className="text-sm text-muted-foreground">
                                    Apache support requires this node to offer
                                    it.
                                </p>

                                <InputError message={errors.web_server} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="ssl_mode">SSL mode</Label>

                                <Select name="ssl_mode" defaultValue="none">
                                    <SelectTrigger id="ssl_mode">
                                        <SelectValue placeholder="Select an SSL mode" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            None
                                        </SelectItem>
                                        <SelectItem value="manual">
                                            Manual
                                        </SelectItem>
                                        <SelectItem value="lets_encrypt">
                                            Let&apos;s Encrypt
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <InputError message={errors.ssl_mode} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="create-domain-button"
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
            title: 'Domains',
            href: index(),
        },
        {
            title: 'Add domain',
            href: WebDomainController.create(),
        },
    ],
};
