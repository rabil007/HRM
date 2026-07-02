export type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export const PAGINATION_PER_PAGE_OPTIONS = [
    10, 15, 20, 25, 30, 50, 100,
] as const;
