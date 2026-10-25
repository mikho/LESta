import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CronSchedule } from '@/lib/cron-schedule';
import { describeSchedule, schedulePresets } from '@/lib/cron-schedule';

const fields: { name: keyof CronSchedule; label: string }[] = [
    { name: 'minute', label: 'Minute' },
    { name: 'hour', label: 'Hour' },
    { name: 'day_of_month', label: 'Day of month' },
    { name: 'month', label: 'Month' },
    { name: 'day_of_week', label: 'Weekday' },
];

const sameSchedule = (a: CronSchedule, b: CronSchedule) =>
    fields.every(({ name }) => a[name] === b[name]);

/**
 * When a cron job runs: a common schedule picked with one click, or the five cron fields for
 * anything else, with what the schedule means in words below either way. The five fields are
 * always what the form submits.
 */
export function CronScheduleField({
    initial,
    errors,
}: {
    initial: CronSchedule;
    errors: Partial<Record<keyof CronSchedule, string>>;
}) {
    const id = useId();
    const [schedule, setSchedule] = useState<CronSchedule>(initial);
    const preset = schedulePresets.find((candidate) =>
        sameSchedule(candidate.schedule, schedule),
    );
    const hasFieldError = fields.some(({ name }) => errors[name]);
    const [custom, setCustom] = useState(preset === undefined || hasFieldError);

    return (
        <fieldset className="space-y-3">
            <legend className="text-sm font-medium">Runs</legend>

            <div
                role="radiogroup"
                aria-label="Schedule"
                className="flex flex-wrap gap-2"
            >
                {schedulePresets.map((candidate) => {
                    const checked =
                        !custom && preset?.label === candidate.label;

                    return (
                        <button
                            key={candidate.label}
                            type="button"
                            role="radio"
                            aria-checked={checked}
                            onClick={() => {
                                setSchedule(candidate.schedule);
                                setCustom(false);
                            }}
                            className={`rounded-md border px-3 py-1.5 text-sm pointer-coarse:py-2.5 ${checked ? 'border-primary bg-primary text-primary-foreground' : 'border-input hover:bg-muted'}`}
                        >
                            {candidate.label}
                        </button>
                    );
                })}
                <button
                    type="button"
                    role="radio"
                    aria-checked={custom}
                    onClick={() => setCustom(true)}
                    className={`rounded-md border px-3 py-1.5 text-sm pointer-coarse:py-2.5 ${custom ? 'border-primary bg-primary text-primary-foreground' : 'border-input hover:bg-muted'}`}
                >
                    Custom
                </button>
            </div>

            {custom ? (
                <>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
                        {fields.map(({ name, label }) => (
                            <div key={name} className="grid gap-2">
                                <Label htmlFor={`${id}-${name}`}>{label}</Label>
                                <Input
                                    id={`${id}-${name}`}
                                    name={name}
                                    value={schedule[name]}
                                    onChange={(e) =>
                                        setSchedule({
                                            ...schedule,
                                            [name]: e.target.value,
                                        })
                                    }
                                    className="font-mono"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                />
                                <InputError message={errors[name]} />
                            </div>
                        ))}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Cron fields: <code>*</code> means every,{' '}
                        <code>*/15</code> every 15th, and <code>1-5</code> a
                        range. Weekdays run from 0 (Sunday) to 6. Times are the
                        server&apos;s.
                    </p>
                </>
            ) : (
                fields.map(({ name }) => (
                    <input
                        key={name}
                        type="hidden"
                        name={name}
                        value={schedule[name]}
                    />
                ))
            )}

            <p aria-live="polite" className="text-sm">
                <span className="text-muted-foreground">This job runs: </span>
                <span className="font-medium">
                    {describeSchedule(schedule)}
                </span>
            </p>
        </fieldset>
    );
}
