export type Role = {
    id: number;
    name: string;
    description: string | null;
    memberships_count?: number;
    permissions?: string[];
};
