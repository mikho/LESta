import { marked } from 'marked';

/**
 * Renders the app's own static documentation content (resources/docs/*.md, served read-only
 * through DocsController -- never user-supplied), so dangerouslySetInnerHTML here carries no XSS
 * risk: the HTML always comes from markdown files shipped with the app itself. Never point this
 * at anything a user could have written.
 */
export function Markdown({ content }: { content: string }) {
    return (
        <div
            className="max-w-none space-y-4 text-sm leading-relaxed [&_code]:rounded [&_code]:bg-muted [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:text-[0.85em] [&_h2]:mt-8 [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:mt-6 [&_h3]:text-base [&_h3]:font-semibold [&_hr]:my-6 [&_hr]:border-sidebar-border/70 dark:[&_hr]:border-sidebar-border [&_li]:my-1 [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-6 [&_p]:my-3 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:border [&_pre]:border-sidebar-border/70 [&_pre]:bg-muted [&_pre]:p-4 dark:[&_pre]:border-sidebar-border [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_table]:block [&_table]:w-full [&_table]:border-collapse [&_table]:overflow-x-auto [&_td]:border [&_td]:border-sidebar-border/70 [&_td]:px-3 [&_td]:py-1.5 dark:[&_td]:border-sidebar-border [&_th]:border [&_th]:border-sidebar-border/70 [&_th]:bg-muted [&_th]:px-3 [&_th]:py-1.5 [&_th]:text-left dark:[&_th]:border-sidebar-border [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-6"
            dangerouslySetInnerHTML={{
                __html: marked.parse(content) as string,
            }}
        />
    );
}
