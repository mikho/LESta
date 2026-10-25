import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useCallback, useState } from 'react';
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
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * The one confirmation for an action that changes or removes something: a title that names the
 * thing, what will happen, and a confirm button that repeats the action's own verb. Used either
 * with a trigger (it opens itself) or controlled (a menu item opens it).
 */
export function ConfirmActionDialog({
    form,
    title,
    description,
    confirmLabel,
    destructive = false,
    trigger,
    open,
    onOpenChange,
    confirmTestId,
}: {
    form: RouteFormDefinition<'post' | 'patch' | 'put' | 'delete'>;
    confirmTestId?: string;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    // Opened by its own trigger, it keeps its own open state, so it can close itself once the
    // action succeeds; a parent that opens it (a menu item) passes open and onOpenChange.
    const [ownOpen, setOwnOpen] = useState(false);
    const isOpen = open ?? ownOpen;
    const setOpen = (next: boolean) => {
        setOwnOpen(next);
        onOpenChange?.(next);
    };

    return (
        <Dialog open={isOpen} onOpenChange={setOpen}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button
                                variant={
                                    destructive ? 'destructive' : 'default'
                                }
                                disabled={processing}
                                asChild
                            >
                                <button type="submit" data-test={confirmTestId}>
                                    {confirmLabel}
                                </button>
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

type ConfirmOptions = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
};

/**
 * The same confirmation for an action that is not a form submission (a request the page sends
 * itself): `await confirm({...})` resolves true when the person confirms and false when they
 * cancel or close it. Render the returned dialog once, anywhere in the page.
 */
export function useConfirm(): [
    (options: ConfirmOptions) => Promise<boolean>,
    ReactNode,
] {
    const [request, setRequest] = useState<{
        options: ConfirmOptions;
        resolve: (confirmed: boolean) => void;
    } | null>(null);

    const confirm = useCallback(
        (options: ConfirmOptions) =>
            new Promise<boolean>((resolve) => setRequest({ options, resolve })),
        [],
    );

    const settle = (confirmed: boolean) => {
        request?.resolve(confirmed);
        setRequest(null);
    };

    const dialog = request && (
        <Dialog open onOpenChange={(open) => !open && settle(false)}>
            <DialogContent>
                <DialogTitle>{request.options.title}</DialogTitle>
                <DialogDescription>
                    {request.options.description}
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <Button variant="secondary" onClick={() => settle(false)}>
                        Cancel
                    </Button>
                    <Button
                        variant={
                            request.options.destructive
                                ? 'destructive'
                                : 'default'
                        }
                        onClick={() => settle(true)}
                    >
                        {request.options.confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );

    return [confirm, dialog];
}
