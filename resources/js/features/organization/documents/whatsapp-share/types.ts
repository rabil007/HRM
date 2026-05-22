export type ShareLinkDocument = {
    id: number;
    name: string;
    share_url: string;
};

export type ShareLinksResponse = {
    documents: ShareLinkDocument[];
};
