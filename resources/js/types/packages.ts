export type PackageLimitRow = {
    resource_type: string;
    configured: boolean;
    limit_value: number | null;
};

export type Package = {
    uuid: string;
    name: string;
    description: string | null;
    is_active: boolean;
    accounts_count?: number;
    limits?: PackageLimitRow[];
};
