export type ShareLinkDocument = {
    id: number;
    name: string;
};

export type ShareLinksResponse = {
    share_url: string;
    documents: ShareLinkDocument[];
};

export type FolderShareItem = {
    employee_id: number;
    name: string;
    share_url: string;
};

export type FolderShareLinksResponse = {
    shares: FolderShareItem[];
};
