import type { ProvisioningStatus, SuspensionSource } from './domains';

export type CronJobExecution = {
    started_at: string;
    finished_at: string;
    exit_code: number;
    output: string;
};

export type CronJob = {
    uuid: string;
    minute: string;
    hour: string;
    day_of_month: string;
    month: string;
    day_of_week: string;
    command: string;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
    provisioning_status: ProvisioningStatus | null;
    executions?: CronJobExecution[];
};
