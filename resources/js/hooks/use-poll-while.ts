import { usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Reloads the named props every few seconds while `active` is true, and stops as soon as it is
 * not: a page with a change still being applied on the server keeps itself current, and a
 * settled page makes no requests at all.
 */
export function usePollWhile(active: boolean, only: string[], interval = 3000) {
    const { start, stop } = usePoll(interval, { only }, { autoStart: false });

    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }

        return stop;
        // only is a fixed list per page; restarting on its identity would reset the timer.
    }, [active, start, stop]);
}

/**
 * Whether a provisioning status means the server is still working on the last change.
 */
export const isApplying = (status: string | null | undefined): boolean =>
    status === 'pending' || status === 'dispatched';
