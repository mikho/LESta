export type DocsGuideSummary = {
    slug: string;
    title: string;
    description: string;
    admin_only: boolean;
};

export type DocsChapterLink = {
    slug: string;
    title: string;
};

export type DocsChapterSummary = DocsChapterLink & {
    number: number;
};

export type DocsGuide = DocsGuideSummary & {
    intro: string;
    chapters: DocsChapterSummary[];
};

export type DocsChapter = {
    number: number;
    slug: string;
    title: string;
    body: string;
    previous: DocsChapterLink | null;
    next: DocsChapterLink | null;
};
