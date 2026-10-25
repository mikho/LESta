import { Form, Head, Link } from '@inertiajs/react';
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

type Offers = {
    php: boolean;
    automatic_https: boolean;
    apache: boolean;
    php_versions: string[];
};

export default function Create({ offers }: { offers: Offers }) {
    const newestPhp = offers.php_versions[offers.php_versions.length - 1];

    return (
        <>
            <Head title="Add domain" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add domain"
                    description="Host a website on a domain you own. You can add mail and DNS for it afterwards."
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
                        php_version:
                            data.php_version === 'none'
                                ? null
                                : data.php_version,
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
                                    autoCapitalize="none"
                                    spellCheck={false}
                                />

                                <InputError message={errors.domain} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="aliases">
                                    Other addresses for the same site
                                </Label>

                                <Textarea
                                    id="aliases"
                                    name="aliases"
                                    rows={3}
                                    placeholder={'www.example.com'}
                                    aria-describedby="aliases-help"
                                />
                                <p
                                    id="aliases-help"
                                    className="text-sm text-muted-foreground"
                                >
                                    One per line, for example the www address.
                                    Optional.
                                </p>

                                <InputError message={errors.aliases} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="ssl_mode">HTTPS</Label>

                                <Select
                                    name="ssl_mode"
                                    defaultValue={
                                        offers.automatic_https
                                            ? 'lets_encrypt'
                                            : 'none'
                                    }
                                >
                                    <SelectTrigger
                                        id="ssl_mode"
                                        aria-describedby="ssl-help"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {offers.automatic_https && (
                                            <SelectItem value="lets_encrypt">
                                                Automatic (free certificate from
                                                Let&apos;s Encrypt)
                                            </SelectItem>
                                        )}
                                        <SelectItem value="manual">
                                            My own certificate
                                        </SelectItem>
                                        <SelectItem value="none">
                                            Off (HTTP only)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p
                                    id="ssl-help"
                                    className="text-sm text-muted-foreground"
                                >
                                    {offers.automatic_https
                                        ? 'The certificate is issued once the domain points at this server, and renewed by itself.'
                                        : 'Automatic certificates are not offered on this server; ask your provider.'}
                                </p>

                                <InputError message={errors.ssl_mode} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="php_version">PHP</Label>

                                <Select
                                    name="php_version"
                                    defaultValue={
                                        offers.php && newestPhp
                                            ? newestPhp
                                            : 'none'
                                    }
                                >
                                    <SelectTrigger
                                        id="php_version"
                                        aria-describedby="php-help"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {offers.php &&
                                            [...offers.php_versions]
                                                .reverse()
                                                .map((version) => (
                                                    <SelectItem
                                                        key={version}
                                                        value={version}
                                                    >
                                                        PHP {version}
                                                        {version === newestPhp
                                                            ? ' (newest)'
                                                            : ''}
                                                    </SelectItem>
                                                ))}
                                        <SelectItem value="none">
                                            No PHP (plain HTML files only)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p
                                    id="php-help"
                                    className="text-sm text-muted-foreground"
                                >
                                    {offers.php
                                        ? 'WordPress and most site software need PHP. You can change the version later.'
                                        : 'PHP is not offered on this server, so the site can serve plain files only.'}
                                </p>

                                <InputError message={errors.php_version} />
                            </div>

                            <details className="group rounded-lg border p-4">
                                <summary className="cursor-pointer text-sm font-medium">
                                    Advanced
                                </summary>

                                <div className="mt-4 space-y-6">
                                    {offers.apache && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="web_server">
                                                Web server
                                            </Label>

                                            <Select
                                                name="web_server"
                                                defaultValue="nginx"
                                            >
                                                <SelectTrigger id="web_server">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="nginx">
                                                        nginx (recommended)
                                                    </SelectItem>
                                                    <SelectItem value="apache">
                                                        Apache, for sites that
                                                        need .htaccess
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>

                                            <InputError
                                                message={errors.web_server}
                                            />
                                        </div>
                                    )}

                                    <div className="grid gap-2">
                                        <Label htmlFor="web_template">
                                            Template
                                        </Label>

                                        <Input
                                            id="web_template"
                                            name="web_template"
                                            defaultValue="default"
                                            aria-describedby="template-help"
                                        />
                                        <p
                                            id="template-help"
                                            className="text-sm text-muted-foreground"
                                        >
                                            Leave as default unless your
                                            provider gave you another name.
                                        </p>

                                        <InputError
                                            message={errors.web_template}
                                        />
                                    </div>
                                </div>
                            </details>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="create-domain-button"
                                >
                                    Add domain
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
            title: 'Domains',
            href: index(),
        },
        {
            title: 'Add domain',
            href: WebDomainController.create(),
        },
    ],
};
