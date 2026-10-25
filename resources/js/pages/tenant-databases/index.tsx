import { Head, Link } from '@inertiajs/react';
import TenantDatabaseController from '@/actions/App/Http/Controllers/TenantDatabases/TenantDatabaseController';
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
import customerResources from '@/routes/customer-resources';
import tenantDatabases from '@/routes/tenant-databases';
import type { TenantDatabase } from '@/types';

export default function Index({
    customers,
    tenantDatabases: paginatedTenantDatabases,
    search: initialSearch,
}: {
    customers?: CustomerListing<CustomerItem<TenantDatabase>>;
    tenantDatabases: Paginated<TenantDatabase> | null;
    search: string;
}) {
    if (customers) {
        return (
            <CustomerResourceIndex
                title="Databases"
                description="Every customer's databases, grouped by node and account"
                indexUrl={tenantDatabases.index.url()}
                listing={customers}
                emptyMessage="No customer has a database yet."
                resourceNoun={{ one: 'database', other: 'databases' }}
                emptyFilteredMessage="No databases match these filters."
                columns={[
                    {
                        header: 'Label',
                        sortKey: 'label',
                        cell: (item) => (
                            <Link
                                href={customerResources.show({
                                    type: 'databases',
                                    uuid: item.uuid,
                                })}
                                className="font-medium underline"
                            >
                                {item.label}
                            </Link>
                        ),
                    },
                    { header: 'Database', cell: (item) => item.database_name },
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

    if (paginatedTenantDatabases === null) {
        return (
            <>
                <Head title="Databases" />

                <div className="p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

    return (
        <OwnResourceList
            title="Databases"
            description="MariaDB databases for your sites and applications"
            addHref={TenantDatabaseController.create()}
            addLabel="Add database"
            indexUrl={tenantDatabases.index.url()}
            search={initialSearch}
            noun={{ one: 'database', other: 'databases' }}
            items={paginatedTenantDatabases}
            pollProp="tenantDatabases"
            empty={{
                title: 'No databases yet',
                body: 'Add a database when a site or application needs one, such as WordPress. Its name, user and password are shown when it is created.',
            }}
            name={(tenantDatabase) => tenantDatabase.label}
            manageHref={(tenantDatabase) =>
                TenantDatabaseController.edit(tenantDatabase)
            }
            columns={[
                {
                    header: 'Database name',
                    cell: (tenantDatabase) => (
                        <span className="font-mono text-xs">
                            {tenantDatabase.database_name}
                        </span>
                    ),
                },
            ]}
            moreActions={(tenantDatabase) => [
                tenantDatabase.suspended_at
                    ? {
                          label: 'Unsuspend',
                          confirm: {
                              form: TenantDatabaseController.unsuspend.form(
                                  tenantDatabase,
                              ),
                              title: `Unsuspend ${tenantDatabase.label}?`,
                              description: 'Its users can connect again.',
                              confirmLabel: 'Unsuspend',
                          },
                      }
                    : {
                          label: 'Suspend',
                          confirm: {
                              form: TenantDatabaseController.suspend.form(
                                  tenantDatabase,
                              ),
                              title: `Suspend ${tenantDatabase.label}?`,
                              description:
                                  'Its users cannot connect until you unsuspend it, so sites using it stop working. The data is kept.',
                              confirmLabel: 'Suspend',
                              destructive: true,
                          },
                      },
                {
                    label: 'Delete',
                    confirm: {
                        form: TenantDatabaseController.destroy.form(
                            tenantDatabase,
                        ),
                        title: `Delete ${tenantDatabase.label}?`,
                        description:
                            'This cannot be undone. The database, all its data and all its users are removed from the server; take a backup first if you need them.',
                        confirmLabel: 'Delete database',
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
            title: 'Databases',
            href: tenantDatabases.index(),
        },
    ],
};
