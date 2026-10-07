import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { apiDelete, apiGet, apiPost, apiPut } from '@/lib/api';

type Entry = {
    name: string;
    type: 'file' | 'directory';
    size: number;
    mod_time: string;
};

type ObserveDirectoryData = { type: 'directory'; entries: Entry[] };
type ObserveFileData = {
    type: 'file';
    size: number;
    mod_time: string;
    content_base64: string;
};

type OperationStatus = {
    status: string;
    data: ObserveDirectoryData | ObserveFileData | null;
    errors: { code: string; message: string; field?: string | null }[];
};

const TERMINAL_STATUSES = new Set([
    'applied',
    'already_applied',
    'rejected',
    'failed',
    'degraded',
]);

/** Mirrors StoreFileRequest::MAX_FILE_BYTES on the server. */
const MAX_UPLOAD_BYTES = 4 * 1024 * 1024;

/** UTF-8-safe base64 encode/decode, since bare btoa/atob only handle Latin1. */
function encodeBase64(text: string): string {
    return btoa(
        Array.from(new TextEncoder().encode(text))
            .map((byte) => String.fromCharCode(byte))
            .join(''),
    );
}

function decodeBase64(base64: string): string {
    const bytes = Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));

    return new TextDecoder().decode(bytes);
}

async function waitForOperation(statusUrl: string): Promise<OperationStatus> {
    for (;;) {
        const result = await apiGet<OperationStatus>(statusUrl);

        if (TERMINAL_STATUSES.has(result.status)) {
            return result;
        }

        await new Promise((resolve) => setTimeout(resolve, 750));
    }
}

export default function Index({
    webDomain,
}: {
    webDomain: { uuid: string; domain: string };
}) {
    const base = `/domains/${webDomain.uuid}/files`;

    const [path, setPath] = useState('');
    const [entries, setEntries] = useState<Entry[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const [editingFile, setEditingFile] = useState<string | null>(null);
    const [editingContent, setEditingContent] = useState('');

    const [newItemDialog, setNewItemDialog] = useState<
        'file' | 'directory' | null
    >(null);
    const [newItemName, setNewItemName] = useState('');

    const [renaming, setRenaming] = useState<string | null>(null);
    const [renameValue, setRenameValue] = useState('');

    const fileInputRef = useRef<HTMLInputElement>(null);

    const joinPath = (base: string, name: string) =>
        base === '' ? name : `${base}/${name}`;

    const refresh = useCallback(
        async (targetPath: string) => {
            setLoading(true);
            setError(null);

            try {
                const dispatched = await apiPost<{ operation_id: number }>(
                    `${base}/observe`,
                    { path: targetPath },
                );
                const result = await waitForOperation(
                    `${base}/operations/${dispatched.operation_id}`,
                );

                if (
                    result.status !== 'applied' ||
                    result.data?.type !== 'directory'
                ) {
                    setError(
                        result.errors[0]?.message ??
                            'Could not list this directory.',
                    );
                    setEntries([]);

                    return;
                }

                setEntries(result.data.entries);
                setPath(targetPath);
            } catch (e) {
                setError(
                    e instanceof Error ? e.message : 'Something went wrong.',
                );
            } finally {
                setLoading(false);
            }
        },
        [base],
    );

    useEffect(() => {
        // The standard "load on mount" effect: refresh's own setState calls
        // happen inside its async body, after an await, never synchronously
        // during this effect itself -- the lint rule's own heuristic can't
        // see through that.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void refresh('');
    }, [refresh]);

    async function openFile(name: string) {
        setBusy(true);
        setError(null);

        try {
            const dispatched = await apiPost<{ operation_id: number }>(
                `${base}/observe`,
                { path: joinPath(path, name) },
            );
            const result = await waitForOperation(
                `${base}/operations/${dispatched.operation_id}`,
            );

            if (result.status !== 'applied' || result.data?.type !== 'file') {
                setError(
                    result.errors[0]?.message ?? 'Could not read this file.',
                );

                return;
            }

            setEditingContent(decodeBase64(result.data.content_base64));
            setEditingFile(name);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    }

    async function saveFile() {
        if (editingFile === null) {
            return;
        }

        setBusy(true);

        try {
            const dispatched = await apiPut<{ operation_id: number }>(base, {
                path: joinPath(path, editingFile),
                content_base64: encodeBase64(editingContent),
            });
            const result = await waitForOperation(
                `${base}/operations/${dispatched.operation_id}`,
            );

            if (result.status !== 'applied') {
                setError(
                    result.errors[0]?.message ?? 'Could not save this file.',
                );

                return;
            }

            setEditingFile(null);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    }

    async function createItem() {
        if (newItemDialog === null || newItemName.trim() === '') {
            return;
        }

        setBusy(true);

        try {
            const dispatched = await apiPost<{ operation_id: number }>(base, {
                path: joinPath(path, newItemName.trim()),
                is_directory: newItemDialog === 'directory',
            });
            const result = await waitForOperation(
                `${base}/operations/${dispatched.operation_id}`,
            );

            if (result.status !== 'applied') {
                setError(
                    result.errors[0]?.message ?? 'Could not create this item.',
                );

                return;
            }

            setNewItemDialog(null);
            setNewItemName('');
            await refresh(path);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    }

    async function uploadFile(file: File) {
        setError(null);

        if (file.size > MAX_UPLOAD_BYTES) {
            setError(
                `This file is larger than ${MAX_UPLOAD_BYTES / (1024 * 1024)} MB, the most the file manager can upload.`,
            );

            return;
        }

        setBusy(true);

        try {
            const buffer = await file.arrayBuffer();
            const base64 = btoa(
                Array.from(new Uint8Array(buffer))
                    .map((byte) => String.fromCharCode(byte))
                    .join(''),
            );

            const dispatched = await apiPost<{ operation_id: number }>(base, {
                path: joinPath(path, file.name),
                content_base64: base64,
            });
            const result = await waitForOperation(
                `${base}/operations/${dispatched.operation_id}`,
            );

            if (result.status !== 'applied') {
                setError(
                    result.errors[0]?.message ?? 'Could not upload this file.',
                );

                return;
            }

            await refresh(path);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    }

    async function rename() {
        if (renaming === null || renameValue.trim() === '') {
            return;
        }

        setBusy(true);

        try {
            const dispatched = await apiPut<{ operation_id: number }>(base, {
                path: joinPath(path, renaming),
                new_path: joinPath(path, renameValue.trim()),
            });
            const result = await waitForOperation(
                `${base}/operations/${dispatched.operation_id}`,
            );

            if (result.status !== 'applied') {
                setError(
                    result.errors[0]?.message ?? 'Could not rename this item.',
                );

                return;
            }

            setRenaming(null);
            await refresh(path);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    }

    async function remove(entry: Entry) {
        if (!confirm(`Delete "${entry.name}"? This cannot be undone.`)) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const dispatched = await apiDelete<{ operation_id: number }>(base, {
                path: joinPath(path, entry.name),
                recursive: entry.type === 'directory',
            });
            const result = await waitForOperation(
                `${base}/operations/${dispatched.operation_id}`,
            );

            if (result.status !== 'applied') {
                setError(
                    result.errors[0]?.message ?? 'Could not delete this item.',
                );

                return;
            }

            await refresh(path);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    }

    const breadcrumbs = ['', ...path.split('/').filter(Boolean)];

    return (
        <>
            <Head title={`Files – ${webDomain.domain}`} />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Heading
                    title="Files"
                    description={`Browse and edit ${webDomain.domain}'s own webroot.`}
                />

                {error && (
                    <div className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
                        {error}
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-1 text-sm">
                        {breadcrumbs.map((segment, index) => {
                            const target = breadcrumbs
                                .slice(1, index + 1)
                                .join('/');

                            return (
                                <span
                                    key={index}
                                    className="flex items-center gap-1"
                                >
                                    {index > 0 && (
                                        <span className="text-muted-foreground">
                                            /
                                        </span>
                                    )}
                                    <button
                                        type="button"
                                        className="hover:underline"
                                        onClick={() => void refresh(target)}
                                    >
                                        {index === 0 ? 'public' : segment}
                                    </button>
                                </span>
                            );
                        })}
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setNewItemDialog('directory')}
                        >
                            New folder
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setNewItemDialog('file')}
                        >
                            New file
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => fileInputRef.current?.click()}
                            aria-describedby="upload-limit"
                        >
                            Upload
                        </Button>
                        <span
                            id="upload-limit"
                            className="text-xs text-muted-foreground"
                        >
                            Up to {MAX_UPLOAD_BYTES / (1024 * 1024)} MB per file
                        </span>
                        <input
                            ref={fileInputRef}
                            type="file"
                            className="hidden"
                            onChange={(e) => {
                                const file = e.target.files?.[0];

                                if (file) {
                                    void uploadFile(file);
                                }

                                e.target.value = '';
                            }}
                        />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">Size</th>
                                <th className="px-4 py-2 font-medium">
                                    Modified
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {!loading && entries.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        This folder is empty.
                                    </td>
                                </tr>
                            )}
                            {loading && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        Loading…
                                    </td>
                                </tr>
                            )}
                            {entries.map((entry) => (
                                <tr
                                    key={entry.name}
                                    className="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                                >
                                    <td className="px-4 py-2">
                                        <button
                                            type="button"
                                            className="hover:underline"
                                            onClick={() =>
                                                entry.type === 'directory'
                                                    ? void refresh(
                                                          joinPath(
                                                              path,
                                                              entry.name,
                                                          ),
                                                      )
                                                    : void openFile(entry.name)
                                            }
                                        >
                                            {entry.type === 'directory'
                                                ? '📁'
                                                : '📄'}{' '}
                                            {entry.name}
                                        </button>
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {entry.type === 'file'
                                            ? entry.size
                                            : '—'}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {new Date(
                                            entry.mod_time,
                                        ).toLocaleString()}
                                    </td>
                                    <td className="px-4 py-2">
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                className="text-muted-foreground hover:underline"
                                                onClick={() => {
                                                    setRenaming(entry.name);
                                                    setRenameValue(entry.name);
                                                }}
                                            >
                                                Rename
                                            </button>
                                            <button
                                                type="button"
                                                className="text-destructive hover:underline"
                                                onClick={() =>
                                                    void remove(entry)
                                                }
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {busy && (
                    <p className="text-sm text-muted-foreground">Working…</p>
                )}
            </div>

            <Dialog
                open={editingFile !== null}
                onOpenChange={(open) => !open && setEditingFile(null)}
            >
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editingFile}</DialogTitle>
                    </DialogHeader>
                    <Textarea
                        value={editingContent}
                        onChange={(e) => setEditingContent(e.target.value)}
                        className="min-h-96 font-mono text-sm"
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            onClick={() => void saveFile()}
                            disabled={busy}
                        >
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={newItemDialog !== null}
                onOpenChange={(open) => !open && setNewItemDialog(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {newItemDialog === 'directory'
                                ? 'New folder'
                                : 'New file'}
                        </DialogTitle>
                    </DialogHeader>
                    <Input
                        value={newItemName}
                        onChange={(e) => setNewItemName(e.target.value)}
                        placeholder="name"
                        autoFocus
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            onClick={() => void createItem()}
                            disabled={busy}
                        >
                            Create
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={renaming !== null}
                onOpenChange={(open) => !open && setRenaming(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Rename "{renaming}"</DialogTitle>
                    </DialogHeader>
                    <Input
                        value={renameValue}
                        onChange={(e) => setRenameValue(e.target.value)}
                        autoFocus
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            onClick={() => void rename()}
                            disabled={busy}
                        >
                            Rename
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
