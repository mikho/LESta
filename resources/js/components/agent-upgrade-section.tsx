import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import NodeAgentUpgradeController from '@/actions/App/Http/Controllers/Nodes/NodeAgentUpgradeController';
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
import {
    generateUpgradeKeyPair,
    importUpgradePrivateKey,
    signUpgrade,
    supportsEd25519,
    upgradeMessage,
} from '@/lib/upgrade-keys';
import type { Node, UpgradeKey } from '@/types';

type Descriptor = {
    node_uuid: string;
    current_version: string | null;
    release: { version: string; sha256: string } | null;
    key: { id: string; name: string; public_key: string } | null;
    upgradeable: boolean;
    reason: string | null;
};

const LIFETIME_SECONDS = 3600;
const KEEP_KEY = 'keep';

function UpgradeDialog({
    node,
    upgradeKeys,
}: {
    node: Node;
    upgradeKeys: UpgradeKey[];
}) {
    const [open, setOpen] = useState(false);
    const [descriptor, setDescriptor] = useState<Descriptor | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [privateKeyPem, setPrivateKeyPem] = useState('');
    const [nextKey, setNextKey] = useState(KEEP_KEY);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        fetch(NodeAgentUpgradeController.show.url(node), {
            headers: { Accept: 'application/json' },
        })
            .then((response) =>
                response.ok
                    ? response.json()
                    : Promise.reject(new Error(`Status ${response.status}`)),
            )
            .then(setDescriptor)
            .catch((e: Error) => setLoadError(e.message));
    }, [open, node]);

    async function readFile(file: File | undefined) {
        if (file) {
            setPrivateKeyPem(await file.text());
        }
    }

    async function submit() {
        if (descriptor?.release == null || descriptor.key == null) {
            return;
        }

        setError(null);
        setProcessing(true);

        try {
            if (!(await supportsEd25519())) {
                throw new Error(
                    'This browser cannot sign with Ed25519. Use a current Chrome, Firefox or Safari.',
                );
            }

            const { key, publicKey } =
                await importUpgradePrivateKey(privateKeyPem);

            if (publicKey !== descriptor.key.public_key) {
                throw new Error(
                    `This private key does not belong to the key this node trusts (${descriptor.key.name}).`,
                );
            }

            const next =
                nextKey === KEEP_KEY
                    ? null
                    : (upgradeKeys.find(
                          (candidate) => candidate.id === nextKey,
                      ) ?? null);
            const expiresAt = Math.floor(Date.now() / 1000) + LIFETIME_SECONDS;
            const signature = await signUpgrade(
                key,
                upgradeMessage(
                    descriptor.node_uuid,
                    descriptor.release.version,
                    descriptor.release.sha256,
                    expiresAt,
                    next?.public_key ?? '',
                ),
            );

            router.post(
                NodeAgentUpgradeController.store.url(node),
                {
                    version: descriptor.release.version,
                    sha256: descriptor.release.sha256,
                    expires_at: expiresAt,
                    signature,
                    next_key: next?.id ?? null,
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setPrivateKeyPem('');
                        setOpen(false);
                    },
                    onError: (errors) =>
                        setError(errors.upgrade ?? Object.values(errors)[0]),
                    onFinish: () => setProcessing(false),
                },
            );
        } catch (e) {
            setError(
                e instanceof Error && e.message.length > 0
                    ? e.message
                    : 'The private key could not be read. Use the PEM file you saved when the key was created.',
            );
            setProcessing(false);
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setDescriptor(null);
                setLoadError(null);
                setPrivateKeyPem('');
                setError(null);
                setNextKey(KEEP_KEY);
            }}
        >
            <DialogTrigger asChild>
                <Button data-test="upgrade-agent-button">Upgrade agent</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Upgrade the agent on {node.name}</DialogTitle>
                <DialogDescription>
                    You sign the upgrade here, in your browser, with the private
                    key that belongs to this node's upgrade key. The key is not
                    sent anywhere; the node checks the signature itself.
                </DialogDescription>

                {loadError && <InputError message={loadError} />}
                {!descriptor && !loadError && (
                    <p className="text-sm text-muted-foreground">Loading...</p>
                )}

                {descriptor && !descriptor.upgradeable && (
                    <InputError message={descriptor.reason ?? undefined} />
                )}

                {descriptor?.upgradeable && descriptor.release && (
                    <div className="space-y-4">
                        <dl className="space-y-1 text-sm">
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">
                                    Version
                                </dt>
                                <dd>
                                    {descriptor.current_version ?? 'unknown'} to{' '}
                                    <strong>
                                        {descriptor.release.version}
                                    </strong>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    sha256 you are signing
                                </dt>
                                <dd className="font-mono text-xs break-all">
                                    {descriptor.release.sha256}
                                </dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">
                                    Node trusts
                                </dt>
                                <dd>{descriptor.key?.name}</dd>
                            </div>
                        </dl>
                        <p className="text-xs text-muted-foreground">
                            Compare the sha256 with the one the build published
                            before you sign: it is what makes sure you sign the
                            binary you meant to.
                        </p>

                        <div className="grid gap-2">
                            <Label htmlFor="private-key">
                                Private key (PEM)
                            </Label>
                            <Input
                                type="file"
                                accept=".pem,.key,text/plain"
                                aria-label="Private key file"
                                onChange={(event) =>
                                    readFile(event.target.files?.[0])
                                }
                            />
                            <Textarea
                                id="private-key"
                                rows={4}
                                value={privateKeyPem}
                                onChange={(event) =>
                                    setPrivateKeyPem(event.target.value)
                                }
                                placeholder="-----BEGIN PRIVATE KEY-----"
                                autoComplete="off"
                                spellCheck={false}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="next-key">
                                Key after the upgrade
                            </Label>
                            <Select value={nextKey} onValueChange={setNextKey}>
                                <SelectTrigger id="next-key">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={KEEP_KEY}>
                                        Keep {descriptor.key?.name}
                                    </SelectItem>
                                    {upgradeKeys
                                        .filter(
                                            (candidate) =>
                                                candidate.id !==
                                                descriptor.key?.id,
                                        )
                                        .map((candidate) => (
                                            <SelectItem
                                                key={candidate.id}
                                                value={candidate.id}
                                            >
                                                Switch to {candidate.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                )}

                <InputError message={error ?? undefined} />

                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={
                            !descriptor?.upgradeable ||
                            privateKeyPem.trim() === '' ||
                            processing
                        }
                        onClick={submit}
                        data-test="sign-and-upgrade-button"
                    >
                        Sign and upgrade
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function KeyGenerator() {
    const [name, setName] = useState('');
    const [created, setCreated] = useState<{
        name: string;
        privateKeyPem: string;
    } | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function create() {
        setError(null);

        try {
            if (!(await supportsEd25519())) {
                throw new Error(
                    'This browser cannot make Ed25519 keys. Use a current Chrome, Firefox or Safari.',
                );
            }

            const pair = await generateUpgradeKeyPair();

            router.post(
                NodeAgentUpgradeController.storeKey.url(),
                { name, public_key: pair.publicKey },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setCreated({ name, privateKeyPem: pair.privateKeyPem });
                        setName('');
                    },
                    onError: (errors) =>
                        setError(Object.values(errors)[0] ?? null),
                },
            );
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Could not make a key.');
        }
    }

    function download() {
        if (!created) {
            return;
        }

        const url = URL.createObjectURL(
            new Blob([created.privateKeyPem], { type: 'text/plain' }),
        );
        const link = document.createElement('a');
        link.href = url;
        link.download = `lesta-upgrade-key-${created.name.replace(/[^A-Za-z0-9_-]+/g, '-')}.pem`;
        link.click();
        URL.revokeObjectURL(url);
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-end gap-2">
                <div className="grid gap-2">
                    <Label htmlFor="new-key-name">New key pair</Label>
                    <Input
                        id="new-key-name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="Name, for example the node or your own"
                    />
                </div>
                <Button
                    variant="outline"
                    disabled={name.trim() === ''}
                    onClick={create}
                    data-test="generate-upgrade-key-button"
                >
                    Create key pair
                </Button>
            </div>
            <InputError message={error ?? undefined} />

            {created && (
                <div className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-200/20 dark:bg-amber-700/10">
                    <p className="font-medium">
                        Save the private key for {created.name} now
                    </p>
                    <p>
                        It was made in your browser and is shown only here. The
                        panel keeps the public half only, so nobody, including
                        you, can get this one back. You need it every time you
                        upgrade a node that trusts this key.
                    </p>
                    <div className="flex gap-2">
                        <Button size="sm" onClick={download}>
                            Download .pem
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => setCreated(null)}
                        >
                            I have saved it
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

export function AgentUpgradeSection({
    node,
    upgradeKeys,
    trustedUpgradeKey,
    canManageKeys,
}: {
    node: Node;
    upgradeKeys: UpgradeKey[];
    trustedUpgradeKey: string | null;
    canManageKeys: boolean;
}) {
    const NONE = 'none';

    function assign(value: string) {
        router.put(
            NodeAgentUpgradeController.assignKey.url(node),
            { upgrade_key: value === NONE ? null : value },
            { preserveScroll: true },
        );
    }

    return (
        <div className="space-y-4 rounded-lg border p-4">
            <Heading
                variant="small"
                title="Agent upgrade"
                description={`This node runs agent ${node.agent_version ?? 'unknown'}. An upgrade is signed with your private upgrade key and applied only if the node's own key verifies it.`}
            />

            <div className="grid gap-2">
                <Label htmlFor="trusted-key">Key this node trusts</Label>
                <Select
                    value={trustedUpgradeKey ?? NONE}
                    onValueChange={assign}
                >
                    <SelectTrigger id="trusted-key">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>None recorded</SelectItem>
                        {upgradeKeys.map((key) => (
                            <SelectItem key={key.id} value={key.id}>
                                {key.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                    This is a note of what is installed on the node. It is set
                    when the node is installed with --upgrade-public-key and
                    changes when an upgrade switches keys.
                </p>
            </div>

            <UpgradeDialog node={node} upgradeKeys={upgradeKeys} />

            {canManageKeys && (
                <div className="space-y-3 border-t pt-4">
                    <Heading
                        variant="small"
                        title="Upgrade keys"
                        description="Use one key for many nodes, or make one per node."
                    />

                    <ul className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        {upgradeKeys.length === 0 && (
                            <li className="px-4 py-2 text-sm text-muted-foreground">
                                No keys yet.
                            </li>
                        )}
                        {upgradeKeys.map((key) => (
                            <li
                                key={key.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-4 py-2 text-sm"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">{key.name}</p>
                                    <p className="font-mono text-xs break-all text-muted-foreground">
                                        {key.public_key}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Trusted by {key.nodes}{' '}
                                        {key.nodes === 1 ? 'node' : 'nodes'}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    disabled={key.nodes > 0}
                                    onClick={() =>
                                        router.delete(
                                            NodeAgentUpgradeController.destroyKey.url(
                                                key.id,
                                            ),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </li>
                        ))}
                    </ul>

                    <KeyGenerator />
                </div>
            )}
        </div>
    );
}
