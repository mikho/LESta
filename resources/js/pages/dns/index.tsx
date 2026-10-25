import { Head, Link } from '@inertiajs/react';
import DnsZoneController from '@/actions/App/Http/Controllers/Dns/DnsZoneController';
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
import type { Paginated } from '@/components/own-resource-list';
import { QuietProvisioningStatus } from '@/components/provisioning-badge';
import customerResources from '@/routes/customer-resources';
import dns from '@/routes/dns';
import type { DnsZone } from '@/types';

/**
 * A time to live in words: how long other servers may keep this answer before asking again.
 */
const formatTtl = (seconds: number): string => {
    if (seconds % 86400 === 0) {
        return `${seconds / 86400} ${seconds === 86400 ? 'day' : 'days'}`;
    }

    if (seconds % 3600 === 0) {
        return `${seconds / 3600} ${seconds === 3600 ? 'hour' : 'hours'}`;
    }

    if (seconds % 60 === 0) {
        return `${seconds / 60} min`;
    }

    return `${seconds} s`;
};

export default function Index({
    customers,
    dnsZones,
    search: initialSearch,
}: {
    customers?: CustomerListing<CustomerItem<DnsZone>>;
    dnsZones: Paginated<DnsZone> | null;
    search: string;
}) {
    if (customers) {
        return (
            <CustomerResourceIndex
                title="DNS"
                description="Every customer's DNS zones, grouped by node and account"
                indexUrl={dns.index.url()}
                listing={customers}
                emptyMessage="No customer has a DNS zone yet."
                resourceNoun={{ one: 'DNS zone', other: 'DNS zones' }}
                emptyFilteredMessage="No DNS zones match these filters."
                columns={[
                    {
                        header: 'Zone',
                        sortKey: 'domain',
                        cell: (item) => (
                            <Link
                                href={customerResources.show({
                                    type: 'dns',
                                    uuid: item.uuid,
                                })}
                                className="font-medium underline"
                            >
                                {item.domain}
                            </Link>
                        ),
                    },
                    {
                        header: 'TTL',
                        sortKey: 'ttl',
                        numeric: true,
                        cell: (item) => formatCount(item.ttl),
                    },
                    {
                        header: 'Records',
                        sortKey: 'records',
                        numeric: true,
                        cell: (item) => formatCount(item.records_count),
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

    if (dnsZones === null) {
        return (
            <>
                <Head title="DNS" />

                <div className="p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

    return (
        <OwnResourceList
            title="DNS"
            description="The name servers' answers for your domains: where the site, mail and other services are found"
            addHref={DnsZoneController.create()}
            addLabel="Add DNS zone"
            indexUrl={dns.index.url()}
            search={initialSearch}
            noun={{ one: 'zone', other: 'zones' }}
            items={dnsZones}
            pollProp="dnsZones"
            empty={{
                title: 'No DNS zones yet',
                body: 'Add a zone when this server should answer DNS for one of your domains. Then point the domain at the name servers shown on the zone page, at the place you registered it.',
            }}
            name={(dnsZone) => dnsZone.domain}
            manageHref={(dnsZone) => DnsZoneController.edit(dnsZone)}
            columns={[
                {
                    header: 'Records',
                    cell: (dnsZone) => dnsZone.records_count ?? 0,
                },
                {
                    header: 'Default TTL',
                    hideOnMobile: true,
                    cell: (dnsZone) => formatTtl(dnsZone.ttl),
                },
            ]}
            moreActions={(dnsZone) => [
                dnsZone.suspended_at
                    ? {
                          label: 'Unsuspend',
                          confirm: {
                              form: DnsZoneController.unsuspend.form(dnsZone),
                              title: `Unsuspend ${dnsZone.domain}?`,
                              description:
                                  'The server answers DNS for this domain again.',
                              confirmLabel: 'Unsuspend',
                          },
                      }
                    : {
                          label: 'Suspend',
                          confirm: {
                              form: DnsZoneController.suspend.form(dnsZone),
                              title: `Suspend ${dnsZone.domain}?`,
                              description:
                                  'The server stops answering DNS for this domain, so its site and mail may stop being found, until you unsuspend it.',
                              confirmLabel: 'Suspend',
                              destructive: true,
                          },
                      },
                {
                    label: 'Delete',
                    confirm: {
                        form: DnsZoneController.destroy.form(dnsZone),
                        title: `Delete ${dnsZone.domain}?`,
                        description:
                            'This cannot be undone. The zone and all its records are removed from the server.',
                        confirmLabel: 'Delete zone',
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
            title: 'DNS',
            href: dns.index(),
        },
    ],
};
