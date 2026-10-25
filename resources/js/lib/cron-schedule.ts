/**
 * Cron schedules in plain words. The five raw fields are always what is saved and run; this
 * only describes them, and offers the common schedules as presets, so a customer never has to
 * know cron syntax to pick one or read one.
 */
export type CronSchedule = {
    minute: string;
    hour: string;
    day_of_month: string;
    month: string;
    day_of_week: string;
};

const dayNames = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

const isNumber = (value: string) => /^\d+$/.test(value);
const pad = (value: string) => value.padStart(2, '0');

/**
 * "Every hour at :15", "Daily at 03:00", "Mondays at 08:30"; the raw fields when the schedule
 * is not one of the common shapes.
 */
export function describeSchedule(schedule: CronSchedule): string {
    const {
        minute,
        hour,
        day_of_month: dayOfMonth,
        month,
        day_of_week: dayOfWeek,
    } = schedule;
    const everyDay = dayOfMonth === '*' && month === '*' && dayOfWeek === '*';

    if (everyDay && hour === '*') {
        if (minute === '*') {
            return 'Every minute';
        }

        const step = /^\*\/(\d+)$/.exec(minute);

        if (step) {
            return `Every ${step[1]} minutes`;
        }

        if (isNumber(minute)) {
            return minute === '0'
                ? 'Every hour, on the hour'
                : `Every hour at :${pad(minute)}`;
        }
    }

    if (isNumber(minute) && isNumber(hour)) {
        const time = `${pad(hour)}:${pad(minute)}`;

        if (everyDay) {
            return `Daily at ${time}`;
        }

        if (
            dayOfMonth === '*' &&
            month === '*' &&
            isNumber(dayOfWeek) &&
            Number(dayOfWeek) <= 7
        ) {
            return `${dayNames[Number(dayOfWeek)]}s at ${time}`;
        }

        if (dayOfWeek === '*' && month === '*' && isNumber(dayOfMonth)) {
            return `Monthly on day ${dayOfMonth} at ${time}`;
        }
    }

    return `${minute} ${hour} ${dayOfMonth} ${month} ${dayOfWeek}`;
}

export type SchedulePreset = {
    label: string;
    schedule: CronSchedule;
};

/**
 * The schedules most jobs need. Times are picked off the hour and off midnight, so many jobs on
 * one server do not all start at once.
 */
export const schedulePresets: SchedulePreset[] = [
    {
        label: 'Every 5 minutes',
        schedule: {
            minute: '*/5',
            hour: '*',
            day_of_month: '*',
            month: '*',
            day_of_week: '*',
        },
    },
    {
        label: 'Every hour',
        schedule: {
            minute: '17',
            hour: '*',
            day_of_month: '*',
            month: '*',
            day_of_week: '*',
        },
    },
    {
        label: 'Every day',
        schedule: {
            minute: '30',
            hour: '3',
            day_of_month: '*',
            month: '*',
            day_of_week: '*',
        },
    },
    {
        label: 'Every week',
        schedule: {
            minute: '30',
            hour: '3',
            day_of_month: '*',
            month: '*',
            day_of_week: '1',
        },
    },
    {
        label: 'Every month',
        schedule: {
            minute: '30',
            hour: '3',
            day_of_month: '1',
            month: '*',
            day_of_week: '*',
        },
    },
];
