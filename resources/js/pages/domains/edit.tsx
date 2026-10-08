import { Form, Head, Link } from '@inertiajs/react';
import WebDomainController from '@/actions/App/Http/Controllers/Domains/WebDomainController';
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
import domains from '@/routes/domains';
import type { SftpAccess, WebDomain } from '@/types';

export default function Edit({
    webDomain,
    sftp,
}: {
    webDomain: WebDomain;
    sftp: SftpAccess;
}) {
    return (
        <>
            <Head title={`Edit ${webDomain.domain}`} />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4">
                <div className="flex items-start justify-between">
                    <Heading
                        title="Edit domain"
                        description="Update this domain's configuration"
                    />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/domains/${webDomain.uuid}/files`}>
                            Files
                        </Link>
                    </Button>
                </div>

                <Form
                    {...WebDomainController.update.form(webDomain)}
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
                                    defaultValue={webDomain.domain}
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
                                    defaultValue={webDomain.aliases.join('\n')}
                                />

                                <InputError message={errors.aliases} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="web_template">Template</Label>

                                <Input
                                    id="web_template"
                                    name="web_template"
                                    defaultValue={webDomain.web_template}
                                />

                                <InputError message={errors.web_template} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="web_server">Web server</Label>

                                <Select
                                    name="web_server"
                                    defaultValue={webDomain.web_server}
                                >
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
                                <Label htmlFor="php_version">PHP version</Label>

                                <Select
                                    name="php_version"
                                    defaultValue={
                                        webDomain.php_version ?? 'none'
                                    }
                                >
                                    <SelectTrigger id="php_version">
                                        <SelectValue placeholder="Select a PHP version" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            None (static only)
                                        </SelectItem>
                                        <SelectItem value="8.1">
                                            PHP 8.1
                                        </SelectItem>
                                        <SelectItem value="8.2">
                                            PHP 8.2
                                        </SelectItem>
                                        <SelectItem value="8.3">
                                            PHP 8.3
                                        </SelectItem>
                                        <SelectItem value="8.4">
                                            PHP 8.4
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <p className="text-sm text-muted-foreground">
                                    Requires this node to offer PHP execution.
                                </p>

                                <InputError message={errors.php_version} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="ssl_mode">SSL mode</Label>

                                <Select
                                    name="ssl_mode"
                                    defaultValue={webDomain.ssl_mode}
                                >
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

                                {webDomain.ssl_mode === 'lets_encrypt' && (
                                    <p
                                        className="text-sm text-muted-foreground"
                                        data-test="certificate-status"
                                    >
                                        {webDomain.certificate_issued_at
                                            ? `Certificate issued ${new Date(webDomain.certificate_issued_at).toLocaleDateString()}${webDomain.certificate_expires_at ? `, expires ${new Date(webDomain.certificate_expires_at).toLocaleDateString()}` : ''}.`
                                            : 'Certificate not issued yet.'}
                                    </p>
                                )}

                                {webDomain.last_certificate_error && (
                                    <p
                                        className="text-sm text-red-600 dark:text-red-400"
                                        data-test="certificate-error"
                                    >
                                        {webDomain.last_certificate_error}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="waf_mode">
                                    Web application firewall
                                </Label>

                                <Select
                                    name="waf_mode"
                                    defaultValue={webDomain.waf_mode}
                                >
                                    <SelectTrigger id="waf_mode">
                                        <SelectValue placeholder="Select a mode" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="off">Off</SelectItem>
                                        <SelectItem value="detect">
                                            Detect only (log, never block)
                                        </SelectItem>
                                        <SelectItem value="block">
                                            Block
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <p className="text-sm text-muted-foreground">
                                    Blocks common attacks such as SQL injection
                                    using the OWASP Core Rule Set. Start with
                                    Detect only to see what would be blocked. If
                                    the node cannot enable it, the update fails
                                    and the previous settings stay.
                                </p>

                                <InputError message={errors.waf_mode} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="waf_excluded_rules">
                                    Excluded rule ids
                                </Label>

                                <Input
                                    id="waf_excluded_rules"
                                    name="waf_excluded_rules"
                                    defaultValue={webDomain.waf_excluded_rules.join(
                                        ', ',
                                    )}
                                    placeholder="942100, 920350"
                                    inputMode="numeric"
                                    autoComplete="off"
                                />

                                <p className="text-sm text-muted-foreground">
                                    Comma separated. Turns off these rules for
                                    this domain only, for example when a rule
                                    blocks a legitimate form.
                                </p>

                                <InputError
                                    message={errors.waf_excluded_rules}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-domain-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="SFTP access"
                        description={`Upload files over SFTP as ${sftp.username}, using your own SSH public key. No password login is offered.`}
                    />

                    <Form
                        {...WebDomainController.updateSshKey.form(webDomain)}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="ssh_public_key">
                                        SSH public key
                                    </Label>

                                    <Textarea
                                        id="ssh_public_key"
                                        name="ssh_public_key"
                                        rows={3}
                                        placeholder="ssh-ed25519 AAAA... you@example.com"
                                    />

                                    <p className="text-sm text-muted-foreground">
                                        {sftp.hasSshPublicKey
                                            ? 'A key is already on file. Paste a new one to replace it, or submit an empty field to remove it.'
                                            : 'No key on file yet — SFTP is disabled for this account until one is added.'}
                                    </p>

                                    <InputError
                                        message={errors.ssh_public_key}
                                    />
                                </div>

                                <Button
                                    disabled={processing}
                                    data-test="update-ssh-key-button"
                                >
                                    Save key
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title={
                            webDomain.suspended_at
                                ? 'Unsuspend domain'
                                : 'Suspend domain'
                        }
                        description={
                            webDomain.suspended_at
                                ? 'Resume serving traffic for this domain.'
                                : 'Stop serving traffic for this domain until it is unsuspended.'
                        }
                    />

                    <Form
                        {...(webDomain.suspended_at
                            ? WebDomainController.unsuspend.form(webDomain)
                            : WebDomainController.suspend.form(webDomain))}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant={
                                    webDomain.suspended_at
                                        ? 'default'
                                        : 'outline'
                                }
                                disabled={processing}
                                data-test="toggle-suspend-domain-button"
                            >
                                {webDomain.suspended_at
                                    ? 'Unsuspend'
                                    : 'Suspend'}
                            </Button>
                        )}
                    </Form>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete domain"
                        description="Delete this domain and all of its resources"
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
                                    data-test="delete-domain-button"
                                >
                                    Delete domain
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete{' '}
                                    {webDomain.domain}?
                                </DialogTitle>
                                <DialogDescription>
                                    Once this domain is deleted, all of its
                                    resources and provisioning state will also
                                    be permanently deleted.
                                </DialogDescription>

                                <Form
                                    {...WebDomainController.destroy.form(
                                        webDomain,
                                    )}
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
                                                    data-test="confirm-delete-domain-button"
                                                >
                                                    Delete domain
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
            title: 'Domains',
            href: domains.index(),
        },
    ],
};
