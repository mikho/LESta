import { Form, Head, Link } from '@inertiajs/react';
import IpRuleController from '@/actions/App/Http/Controllers/IpRules/IpRuleController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NoAccountNotice } from '@/components/no-account-notice';
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
import { index as domainsIndex } from '@/routes/domains';

type IpRule = {
    uuid: string;
    action: 'allow' | 'deny';
    cidr: string;
    note: string | null;
};

type Props = {
    rules: IpRule[] | null;
    limit: number;
};

export default function Index({ rules, limit }: Props) {
    if (rules === null) {
        return (
            <>
                <Head title="IP access rules" />
                <div className="space-y-6 p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="IP access rules" />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="IP access rules"
                        description="Allow or block visitors by IP address on every domain in this account"
                    />

                    <Button variant="outline" asChild>
                        <Link href={domainsIndex()}>Domains</Link>
                    </Button>
                </div>

                <p className="text-sm text-muted-foreground">
                    Deny blocks an address or a range such as 203.0.113.0/24.
                    Allow lets an address through even when a wider deny rule
                    covers it. An address that matches no rule is allowed. To
                    allow only certain addresses, deny 0.0.0.0/0 and ::/0, then
                    allow those addresses. Changes apply to every nginx domain
                    in the account within a minute or so.
                </p>

                <Form
                    {...IpRuleController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid gap-4 sm:grid-cols-[8rem_1fr_1fr_auto] sm:items-start"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="action">Action</Label>
                                <Select name="action" defaultValue="deny">
                                    <SelectTrigger id="action">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="deny">
                                            Deny
                                        </SelectItem>
                                        <SelectItem value="allow">
                                            Allow
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.action} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="cidr">
                                    IP address or range
                                </Label>
                                <Input
                                    id="cidr"
                                    name="cidr"
                                    placeholder="203.0.113.0/24"
                                    autoComplete="off"
                                    required
                                />
                                <InputError message={errors.cidr} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="note">Note (optional)</Label>
                                <Input
                                    id="note"
                                    name="note"
                                    maxLength={100}
                                    autoComplete="off"
                                />
                                <InputError message={errors.note} />
                            </div>

                            <div className="grid gap-2">
                                <span
                                    aria-hidden="true"
                                    className="hidden text-sm sm:block"
                                >
                                    &nbsp;
                                </span>
                                <Button
                                    disabled={processing}
                                    data-test="add-ip-rule-button"
                                >
                                    Add rule
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {rules.length === 0 ? (
                    <p
                        className="text-sm text-muted-foreground"
                        data-test="no-ip-rules"
                    >
                        No rules yet. Every visitor is allowed.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Action</th>
                                    <th className="p-3 font-medium">Address</th>
                                    <th className="p-3 font-medium">Note</th>
                                    <th className="p-3">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rules.map((rule) => (
                                    <tr
                                        key={rule.uuid}
                                        className="border-t"
                                        data-test="ip-rule-row"
                                    >
                                        <td className="p-3 capitalize">
                                            {rule.action}
                                        </td>
                                        <td className="p-3 font-mono">
                                            {rule.cidr}
                                        </td>
                                        <td className="p-3 text-muted-foreground">
                                            {rule.note}
                                        </td>
                                        <td className="p-3 text-right">
                                            <Form
                                                {...IpRuleController.destroy.form(
                                                    rule.uuid,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={processing}
                                                        aria-label={`Remove ${rule.action} rule for ${rule.cidr}`}
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            </Form>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-sm text-muted-foreground">
                    {rules.length} of {limit} rules used.
                </p>
            </div>
        </>
    );
}
