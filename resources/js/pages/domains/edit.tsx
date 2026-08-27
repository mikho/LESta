import { Form, Head } from '@inertiajs/react';
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
import type { WebDomain } from '@/types';

export default function Edit({ webDomain }: { webDomain: WebDomain }) {
    return (
        <>
            <Head title={`Edit ${webDomain.domain}`} />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4">
                <Heading
                    title="Edit domain"
                    description="Update this domain's configuration"
                />

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
