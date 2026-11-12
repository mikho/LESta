import { Head, Link } from '@inertiajs/react';
import MailDomainController from '@/actions/App/Http/Controllers/Mail/MailDomainController';
import {
    CustomerResourceIndex,
    formatCount,
    SuspensionCell,
} from '@/components/customer-resource-index';
import type {
    CustomerItem,
    CustomerListing,
} from '@/components/customer-resource-index';
import { NoAccountNotice } from '@/components/no-account-notice';
import { OwnResourceList } from '@/components/own-resource-list';
import { QuietProvisioningStatus } from '@/components/provisioning-badge';
import customerResources from '@/routes/customer-resources';
import mail from '@/routes/mail';
import type { Paginated } from '@/types';
import type { MailDomain } from '@/types';

export default function Index({
    customers,
    mailDomains,
    search: initialSearch,
}: {
    customers?: CustomerListing<CustomerItem<MailDomain>>;
    mailDomains: Paginated<MailDomain> | null;
    search: string;
}) {
    if (customers) {
        return (
            <CustomerResourceIndex
                title="Mail"
                description="Every customer's mail domains, grouped by node and account"
                indexUrl={mail.index.url()}
                listing={customers}
                emptyMessage="No customer has a mail domain yet."
                resourceNoun={{ one: 'mail domain', other: 'mail domains' }}
                emptyFilteredMessage="No mail domains match these filters."
                columns={[
                    {
                        header: 'Domain',
                        sortKey: 'domain',
                        cell: (item) => (
                            <Link
                                href={customerResources.show({
                                    type: 'mail',
                                    uuid: item.uuid,
                                })}
                                className="font-medium underline"
                            >
                                {item.domain}
                            </Link>
                        ),
                    },
                    {
                        header: 'Mailboxes',
                        sortKey: 'mailboxes',
                        numeric: true,
                        cell: (item) => formatCount(item.accounts_count),
                    },
                    {
                        header: 'Antivirus',
                        cell: (item) =>
                            item.antivirus_enabled ? (
                                'On'
                            ) : (
                                <span className="font-medium text-amber-700 dark:text-amber-400">
                                    Off
                                </span>
                            ),
                    },
                    {
                        header: 'Antispam',
                        cell: (item) =>
                            item.antispam_enabled ? (
                                'On'
                            ) : (
                                <span className="font-medium text-amber-700 dark:text-amber-400">
                                    Off
                                </span>
                            ),
                    },
                    {
                        header: 'DKIM',
                        cell: (item) =>
                            item.dkim_enabled ? (
                                'On'
                            ) : (
                                <span className="text-muted-foreground">
                                    Off
                                </span>
                            ),
                    },
                    {
                        header: 'Suspension',
                        cell: (item) => (
                            <SuspensionCell
                                suspendedAt={item.suspended_at}
                                source={item.suspension_source}
                            />
                        ),
                    },
                    {
                        header: 'Provisioning',
                        cell: (item) => (
                            <QuietProvisioningStatus
                                status={item.provisioning_status}
                                reason={item.provisioning_error}
                            />
                        ),
                    },
                ]}
            />
        );
    }

    if (mailDomains === null) {
        return (
            <>
                <Head title="Mail" />

                <div className="p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

    return (
        <OwnResourceList
            title="Mail"
            description="Mail domains and their mailboxes"
            addHref={MailDomainController.create()}
            addLabel="Add mail domain"
            indexUrl={mail.index.url()}
            search={initialSearch}
            noun={{ one: 'mail domain', other: 'mail domains' }}
            items={mailDomains}
            pollProp="mailDomains"
            empty={{
                title: 'No mail domains yet',
                body: 'Add a mail domain to receive mail for it, then create mailboxes such as info@ on its page.',
            }}
            name={(mailDomain) => mailDomain.domain}
            manageHref={(mailDomain) => MailDomainController.edit(mailDomain)}
            columns={[
                {
                    header: 'Mailboxes',
                    cell: (mailDomain) => mailDomain.accounts_count ?? 0,
                },
            ]}
            moreActions={(mailDomain) => [
                mailDomain.suspended_at
                    ? {
                          label: 'Unsuspend',
                          confirm: {
                              form: MailDomainController.unsuspend.form(
                                  mailDomain,
                              ),
                              title: `Unsuspend ${mailDomain.domain}?`,
                              description:
                                  'Mail for this domain is accepted again.',
                              confirmLabel: 'Unsuspend',
                          },
                      }
                    : {
                          label: 'Suspend',
                          confirm: {
                              form: MailDomainController.suspend.form(
                                  mailDomain,
                              ),
                              title: `Suspend ${mailDomain.domain}?`,
                              description:
                                  'Mail for this domain is refused until you unsuspend it. Mail already in the mailboxes is kept.',
                              confirmLabel: 'Suspend',
                              destructive: true,
                          },
                      },
                {
                    label: 'Delete',
                    confirm: {
                        form: MailDomainController.destroy.form(mailDomain),
                        title: `Delete ${mailDomain.domain}?`,
                        description:
                            'This cannot be undone. The domain, its mailboxes and all their mail are deleted from the server; take a backup first if you need them.',
                        confirmLabel: 'Delete mail domain',
                        destructive: true,
                    },
                },
            ]}
        />
    );
}

Index.layout = {
    breadcrumbs: [
        {
            title: 'Mail',
            href: mail.index(),
        },
    ],
};
