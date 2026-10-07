import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

/**
 * Shows a toast for every validation error no field on the page displays: a rule thrown by an
 * action under a key with no matching input, or a form with no error slot for it. Without this a
 * refused action (restoring a backup already being restored, removing the last owner) fails with
 * no message at all. An error whose key matches a field in the page is left to that field's own
 * inline message, so it is not shown twice.
 */
export function useErrorToast(): void {
    useEffect(() => {
        return router.on('error', (event) => {
            const errors = (event as CustomEvent).detail?.errors as
                Record<string, string | string[]> | undefined;

            Object.entries(errors ?? {}).forEach(([key, value]) => {
                const field = key.split('.')[0];

                if (
                    document.querySelector(
                        `[name="${field}"], [name="${field}[]"]`,
                    )
                ) {
                    return;
                }

                toast.error(Array.isArray(value) ? value[0] : value);
            });
        });
    }, []);
}
