import { Head, Link } from '@inertiajs/react';
import WebDomainController from '@/actions/App/Http/Controllers/Domains/WebDomainController';
import {
    CustomerResourceIndex,
    SuspensionCell,
} from '@/components/customer-resource-index';
import type {
    CustomerItem,
    CustomerListing,
} from '@/components/customer-resource-index';
import { NoAccountNotice } from '@/components/no-account-notice';
import { OwnResourceList } from '@/components/own-resource-list';
import type { Paginated } from '@/components/own-resource-list';
import { QuietProvisioningStatus } from '@/components/provisioning-badge';
import { Button } from '@/components/ui/button';
import customerResources from '@/routes/customer-resources';
import domains from '@/routes/domains';
import { index as ipRulesIndex } from '@/routes/ip-rules';
import type { WebDomain } from '@/types';

const sslModeLabels: Record<WebDomain['ssl_mode'], string> = {
    none: 'None',
    manual: 'Manual',
    lets_encrypt: "Let's Encrypt",
};

const httpsLabels: Record<WebDomain['ssl_mode'], string> = {
    none: 'Off',
    manual: 'Own certificate',
    lets_encrypt: 'On (automatic)',
};

const webServerLabels: Record<WebDomain['web_server'], string> = {
    nginx: 'nginx',
    apache: 'Apache',
};

export default function Index({
    customers,
    webDomains,
    search: initialSearch,
}: {
    customers?: CustomerListing<CustomerItem<WebDomain>>;
    webDomains: Paginated<WebDomain> | null;
    search: string;
}) {
    if (customers) {
        return (
            <CustomerResourceIndex
                title="Domains"
                description="Every customer's web domains, grouped by node and account"
                indexUrl={domains.index.url()}
                listing={customers}
                emptyMessage="No customer has a domain yet."
                resourceNoun={{ one: 'domain', other: 'domains' }}
                emptyFilteredMessage="No domains match these filters."
                columns={[
                    {
                        header: 'Domain',
                        sortKey: 'domain',
                        cell: (item) => (
                            <Link
                                href={customerResources.show({
                                    type: 'domains',
                                    uuid: item.uuid,
                                })}
                                className="font-medium underline"
                            >
                                {item.domain}
                            </Link>
                        ),
                    },
                    {
                        header: 'Aliases',
                        cell: (item) =>
                            item.aliases.length > 0
                                ? item.aliases.join(', ')
                                : '—',
                    },
                    {
                        header: 'SSL',
                        cell: (item) => sslModeLabels[item.ssl_mode],
                    },
                    {
                        header: 'Web server',
                        cell: (item) => webServerLabels[item.web_server],
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

    if (webDomains === null) {
        return (
            <>
                <Head title="Domains" />

                <div className="p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

    return (
        <OwnResourceList
            title="Domains"
            description="Your websites and the addresses they answer on"
            headerActions={
                <Button variant="outline" asChild>
                    <Link href={ipRulesIndex()}>IP access rules</Link>
                </Button>
            }
            addHref={WebDomainController.create()}
            addLabel="Add domain"
            indexUrl={domains.index.url()}
            search={initialSearch}
            noun={{ one: 'domain', other: 'domains' }}
            items={webDomains}
            pollProp="webDomains"
            empty={{
                title: 'No domains yet',
                body: 'Add a domain to host a website on it. You can add mail and DNS for the same domain afterwards.',
            }}
            name={(webDomain) => webDomain.domain}
            manageHref={(webDomain) => WebDomainController.edit(webDomain)}
            columns={[
                {
                    header: 'Aliases',
                    hideOnMobile: true,
                    cell: (webDomain) =>
                        webDomain.aliases.length > 0
                            ? webDomain.aliases.join(', ')
                            : '—',
                },
                {
                    header: 'HTTPS',
                    hideOnMobile: true,
                    cell: (webDomain) => httpsLabels[webDomain.ssl_mode],
                },
                {
                    header: 'Web server',
                    hideOnMobile: true,
                    cell: (webDomain) => webServerLabels[webDomain.web_server],
                },
            ]}
            moreActions={(webDomain) => [
                { label: 'Files', href: domains.files.index(webDomain) },
                { label: 'Logs', href: domains.logs.index(webDomain) },
                webDomain.suspended_at
                    ? {
                          label: 'Unsuspend',
                          confirm: {
                              form: WebDomainController.unsuspend.form(
                                  webDomain,
                              ),
                              title: `Unsuspend ${webDomain.domain}?`,
                              description: 'The site will be reachable again.',
                              confirmLabel: 'Unsuspend',
                          },
                      }
                    : {
                          label: 'Suspend',
                          confirm: {
                              form: WebDomainController.suspend.form(webDomain),
                              title: `Suspend ${webDomain.domain}?`,
                              description:
                                  'Visitors will get an error until you unsuspend it. Its files are kept.',
                              confirmLabel: 'Suspend',
                              destructive: true,
                          },
                      },
                {
                    label: 'Delete',
                    confirm: {
                        form: WebDomainController.destroy.form(webDomain),
                        title: `Delete ${webDomain.domain}?`,
                        description:
                            "This cannot be undone. The site's files and logs are deleted from the server too; take a backup first if you need them.",
                        confirmLabel: 'Delete domain',
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
            title: 'Domains',
            href: domains.index(),
        },
    ],
};
