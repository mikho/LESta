import { formatBytes } from '@/lib/format';
import type { NodeFilesystem } from '@/types';

/** A disk counts as nearly full from this share used. */
export const DISK_FULL_PERCENT = 90;

/**
 * The share of a disk in use, as df shows it: used against used plus what an unprivileged process
 * can still write, so the blocks reserved for root count as unavailable.
 */
export function diskUsedPercent(filesystem: NodeFilesystem): number {
    const used = filesystem.total_bytes - filesystem.free_bytes;
    const usable = used + filesystem.available_bytes;

    return usable <= 0 ? 0 : Math.ceil((used / usable) * 100);
}

/** Each disk with a bar, red once it is nearly full. */
export function NodeDisks({ filesystems }: { filesystems: NodeFilesystem[] }) {
    return (
        <ul className="space-y-3">
            {filesystems.map((filesystem) => {
                const percent = diskUsedPercent(filesystem);
                const full = percent >= DISK_FULL_PERCENT;

                return (
                    <li key={filesystem.mount_point} className="space-y-1">
                        <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                            <span className="font-mono break-all">
                                {filesystem.mount_point}
                            </span>
                            <span
                                className={
                                    full
                                        ? 'font-medium text-red-600 dark:text-red-400'
                                        : 'text-muted-foreground'
                                }
                            >
                                {percent}% used,{' '}
                                {formatBytes(filesystem.available_bytes)} free
                                of {formatBytes(filesystem.total_bytes)}
                            </span>
                        </div>
                        <div
                            role="meter"
                            aria-label={`${filesystem.mount_point} used`}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={Math.min(percent, 100)}
                            className="h-2 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                className={`h-full rounded-full ${full ? 'bg-red-500' : 'bg-primary'}`}
                                style={{ width: `${Math.min(percent, 100)}%` }}
                            />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
