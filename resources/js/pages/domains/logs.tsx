import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiGet, apiPost } from '@/lib/api';

type Counted = { name: string; count: number };

type Summary = {
    requests: number;
    bytes_sent: number;
    visitors: number;
    status: Record<string, number>;
    top_pages: Counted[];
    top_not_found: Counted[];
    top_referrers: Counted[];
    browsers: Counted[];
    by_hour: number[];
    from: string;
    to: string;
    window_partial: boolean;
};

type LogTail = { kind: string; lines: string[]; truncated: boolean };

type OperationStatus<T> = {
    status: string;
    data: T | null;
    errors: { code: string; message: string }[];
};

type HistoryDay = { date: string; requests: number; bytes_sent: number };

type View = 'traffic' | 'access' | 'error';

const TERMINAL_STATUSES = new Set([
    'applied',
    'already_applied',
    'rejected',
    'failed',
    'degraded',
]);

async function waitForOperation<T>(
    statusUrl: string,
): Promise<OperationStatus<T>> {
    for (;;) {
        const result = await apiGet<OperationStatus<T>>(statusUrl);

        if (TERMINAL_STATUSES.has(result.status)) {
            return result;
        }

        await new Promise((resolve) => setTimeout(resolve, 750));
    }
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`;
}

/** A horizontal bar whose width is the share of the largest value, with the number beside it. */
function Bars({
    items,
    format = (n: number) => n.toLocaleString(),
    empty,
}: {
    items: { label: string; value: number }[];
    format?: (n: number) => string;
    empty: string;
}) {
    const max = Math.max(1, ...items.map((item) => item.value));

    if (items.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <ul className="space-y-1.5 text-sm">
            {items.map((item) => (
                <li
                    key={item.label}
                    className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3"
                >
                    <div className="relative min-w-0 overflow-hidden rounded bg-muted/50 px-2 py-1">
                        <div
                            className="absolute inset-y-0 left-0 bg-primary/15"
                            style={{ width: `${(item.value / max) * 100}%` }}
                            aria-hidden="true"
                        />
                        <span className="relative block truncate font-mono">
                            {item.label}
                        </span>
                    </div>
                    <span className="tabular-nums">{format(item.value)}</span>
                </li>
            ))}
        </ul>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-2 rounded-lg border p-4">
            <h3 className="text-sm font-medium">{title}</h3>
            {children}
        </section>
    );
}

export default function Logs({
    webDomain,
    history,
}: {
    webDomain: { uuid: string; domain: string };
    history: HistoryDay[];
}) {
    const base = `/domains/${webDomain.uuid}/logs`;
    const statusBase = `/domains/${webDomain.uuid}/files/operations`;

    const [view, setView] = useState<View>('traffic');
    const [summary, setSummary] = useState<Summary | null>(null);
    const [tail, setTail] = useState<LogTail | null>(null);
    const [filter, setFilter] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(
        async (target: View) => {
            setLoading(true);
            setError(null);

            try {
                const dispatched = await apiPost<{ operation_id: number }>(
                    `${base}/observe`,
                    target === 'traffic'
                        ? { kind: 'access', mode: 'summary' }
                        : { kind: target, mode: 'tail', lines: 300 },
                );
                const result = await waitForOperation<Summary & LogTail>(
                    `${statusBase}/${dispatched.operation_id}`,
                );

                if (result.status !== 'applied' || result.data === null) {
                    setError(
                        result.errors[0]?.message ??
                            'Could not read the log from the server.',
                    );

                    return;
                }

                if (target === 'traffic') {
                    setSummary(result.data);
                } else {
                    setTail(result.data);
                }
            } catch (e) {
                setError(
                    e instanceof Error ? e.message : 'Something went wrong.',
                );
            } finally {
                setLoading(false);
            }
        },
        [base, statusBase],
    );

    useEffect(() => {
        // The standard "load on mount and when the view changes" effect: it
        // starts a request, and everything else happens after the await.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load(view);
    }, [load, view]);

    const download = async (kind: 'access' | 'error') => {
        setError(null);

        try {
            const dispatched = await apiPost<{ operation_id: number }>(
                `${base}/observe`,
                { kind, mode: 'download' },
            );
            const result = await waitForOperation<{ kind: string }>(
                `${statusBase}/${dispatched.operation_id}`,
            );

            if (result.status !== 'applied') {
                setError(
                    result.errors[0]?.message ?? 'Could not download the log.',
                );

                return;
            }

            window.location.href = `${base}/download/${dispatched.operation_id}`;
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Something went wrong.');
        }
    };

    const visibleLines =
        tail?.lines.filter((line) =>
            line.toLowerCase().includes(filter.trim().toLowerCase()),
        ) ?? [];

    const views: { id: View; label: string }[] = [
        { id: 'traffic', label: 'Traffic' },
        { id: 'access', label: 'Access log' },
        { id: 'error', label: 'Error log' },
    ];

    return (
        <>
            <Head title={`Logs and traffic for ${webDomain.domain}`} />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Logs and traffic"
                        description={webDomain.domain}
                    />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/domains/${webDomain.uuid}/edit`}>
                            Back to domain
                        </Link>
                    </Button>
                </div>

                <div
                    role="group"
                    aria-label="What to show"
                    className="flex flex-wrap gap-2"
                >
                    {views.map((item) => (
                        <Button
                            key={item.id}
                            variant={view === item.id ? 'default' : 'outline'}
                            size="sm"
                            aria-pressed={view === item.id}
                            onClick={() => setView(item.id)}
                        >
                            {item.label}
                        </Button>
                    ))}
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={loading}
                        onClick={() => void load(view)}
                    >
                        Refresh
                    </Button>
                </div>

                <div aria-live="polite" className="min-h-5 text-sm">
                    {loading && (
                        <span className="text-muted-foreground">
                            Reading the log from the server…
                        </span>
                    )}
                    {error && (
                        <span
                            className="text-red-600 dark:text-red-400"
                            role="alert"
                        >
                            {error}
                        </span>
                    )}
                </div>

                {view === 'traffic' && (
                    <div className="space-y-4" data-test="traffic-view">
                        {summary && (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {summary.requests.toLocaleString()} requests
                                    from {summary.visitors.toLocaleString()}{' '}
                                    visitors (distinct addresses),{' '}
                                    {formatBytes(summary.bytes_sent)} sent
                                    {summary.from
                                        ? `, ${new Date(summary.from).toLocaleString()} to ${new Date(summary.to).toLocaleString()}`
                                        : ''}
                                    .
                                    {summary.window_partial
                                        ? ' Only the most recent part of the log is included.'
                                        : ''}
                                </p>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <Section title="Responses">
                                        <Bars
                                            empty="No requests in the log yet."
                                            items={[
                                                '2xx',
                                                '3xx',
                                                '4xx',
                                                '5xx',
                                            ].map((code) => ({
                                                label:
                                                    code === '2xx'
                                                        ? '2xx success'
                                                        : code === '3xx'
                                                          ? '3xx redirect'
                                                          : code === '4xx'
                                                            ? '4xx client error'
                                                            : '5xx server error',
                                                value:
                                                    summary.status[code] ?? 0,
                                            }))}
                                        />
                                    </Section>

                                    <Section title="Requests by hour of day">
                                        <Bars
                                            empty="No requests in the log yet."
                                            items={summary.by_hour
                                                .map((value, hour) => ({
                                                    label: `${String(hour).padStart(2, '0')}:00`,
                                                    value,
                                                }))
                                                .filter((h) => h.value > 0)}
                                        />
                                    </Section>

                                    <Section title="Most visited pages">
                                        <Bars
                                            empty="No successful page views yet."
                                            items={summary.top_pages.map(
                                                (p) => ({
                                                    label: p.name,
                                                    value: p.count,
                                                }),
                                            )}
                                        />
                                    </Section>

                                    <Section title="Pages not found (404)">
                                        <Bars
                                            empty="No missing pages. Nice."
                                            items={summary.top_not_found.map(
                                                (p) => ({
                                                    label: p.name,
                                                    value: p.count,
                                                }),
                                            )}
                                        />
                                    </Section>

                                    <Section title="Sites sending visitors">
                                        <Bars
                                            empty="No referrers recorded."
                                            items={summary.top_referrers.map(
                                                (p) => ({
                                                    label: p.name,
                                                    value: p.count,
                                                }),
                                            )}
                                        />
                                    </Section>

                                    <Section title="Browsers">
                                        <Bars
                                            empty="No requests in the log yet."
                                            items={summary.browsers.map(
                                                (p) => ({
                                                    label: p.name,
                                                    value: p.count,
                                                }),
                                            )}
                                        />
                                    </Section>
                                </div>
                            </>
                        )}

                        <Section title="Last 30 days">
                            <Bars
                                empty="No daily figures recorded yet. They appear after the node's daily usage collection."
                                items={history.map((day) => ({
                                    label: `${day.date}, ${formatBytes(day.bytes_sent)}`,
                                    value: day.requests,
                                }))}
                                format={(n) => `${n.toLocaleString()} requests`}
                            />
                        </Section>
                    </div>
                )}

                {view !== 'traffic' && (
                    <div className="space-y-3" data-test="log-view">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="log-filter">
                                    Filter these lines
                                </Label>
                                <Input
                                    id="log-filter"
                                    value={filter}
                                    onChange={(e) => setFilter(e.target.value)}
                                    placeholder="404, an address, a path…"
                                    autoComplete="off"
                                />
                            </div>
                            <Button
                                variant="outline"
                                onClick={() => void download(view)}
                            >
                                Download the log
                            </Button>
                        </div>

                        {tail && (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Showing the last {tail.lines.length} lines
                                    {tail.truncated
                                        ? ', there are earlier lines. Download the log to see more (up to 4 MB).'
                                        : '.'}
                                </p>
                                <pre
                                    className="max-h-[32rem] overflow-auto rounded-md border bg-muted/30 p-3 text-xs leading-relaxed"
                                    tabIndex={0}
                                    aria-label={`${view} log lines`}
                                >
                                    {visibleLines.length > 0
                                        ? visibleLines.join('\n')
                                        : tail.lines.length === 0
                                          ? 'The log is empty.'
                                          : 'No lines match the filter.'}
                                </pre>
                            </>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
