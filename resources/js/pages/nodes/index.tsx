import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import NodeController from '@/actions/App/Http/Controllers/Nodes/NodeController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import nodes from '@/routes/nodes';
import type { Node } from '@/types';

type PaginatedNodes = {
    data: Node[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};

const enrollmentBadgeClasses: Record<Node['enrollment_status'], string> = {
    pending:
        'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    enrolled:
        'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    revoked: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

function EnrollmentBadge({ status }: { status: Node['enrollment_status'] }) {
    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${enrollmentBadgeClasses[status]}`}
        >
            {status}
        </span>
    );
}

export default function Index({
    nodes: paginatedNodes,
    search: initialSearch,
}: {
    nodes: PaginatedNodes;
    search: string;
}) {
    const [search, setSearch] = useState(initialSearch);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                nodes.index.url(),
                { search },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search]);

    return (
        <>
            <Head title="Nodes" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Nodes"
                        description="Manage the platform's infrastructure nodes"
                    />

                    <Button asChild>
                        <Link href={NodeController.create()}>Add node</Link>
                    </Button>
                </div>

                <Input
                    type="search"
                    placeholder="Search nodes…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="max-w-sm"
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">
                                    Hostname
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Enrollment
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Capabilities
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Suspension
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {paginatedNodes.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No nodes yet.
                                    </td>
                                </tr>
                            )}

                            {paginatedNodes.data.map((node) => (
                                <tr
                                    key={node.uuid}
                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 font-medium">
                                        {node.name}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {node.hostname}
                                    </td>
                                    <td className="px-4 py-2">
                                        <EnrollmentBadge
                                            status={node.enrollment_status}
                                        />
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {node.capabilities_count ?? 0}
                                    </td>
                                    <td className="px-4 py-2">
                                        {node.suspended_at ? (
                                            <span className="text-red-600 dark:text-red-400">
                                                Suspended
                                            </span>
                                        ) : (
                                            <span className="text-green-600 dark:text-green-400">
                                                Active
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={NodeController.edit(node)}
                                            >
                                                Manage
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>{paginatedNodes.total} total</span>

                    <div className="flex gap-2">
                        {paginatedNodes.prev_page_url && (
                            <Link
                                href={paginatedNodes.prev_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Previous
                            </Link>
                        )}

                        {paginatedNodes.next_page_url && (
                            <Link
                                href={paginatedNodes.next_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Next
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

Index.layout = {
    breadcrumbs: [
        {
            title: 'Nodes',
            href: nodes.index(),
        },
    ],
};
