<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Reads the app's own static documentation (resources/docs/*.md, adapted from the project's
 * design vault) and splits each guide into its numbered "## Chapter N: Title" sections, so the
 * UI never has to render one huge page -- one chapter per page, with an index per guide. Guides
 * are plain files, not Eloquent models: this class is the entire "repository" for them.
 */
class DocsCatalog
{
    /**
     * @var array<string, array{title: string, description: string, file: string, admin_only: bool}>
     */
    private const array GUIDES = [
        'user-guide' => [
            'title' => 'User Guide',
            'description' => 'For a hosting customer: managing your own account, domains, DNS, mail, databases, cron jobs, and usage.',
            'file' => 'user-guide.md',
            'admin_only' => false,
        ],
        'admin-guide' => [
            'title' => 'Admin Guide',
            'description' => 'For a platform administrator: accounts, resellers, nodes, backups, packages, and roles.',
            'file' => 'admin-guide.md',
            'admin_only' => true,
        ],
        'installation-guide' => [
            'title' => 'Installation Guide',
            'description' => 'For setting up a real infrastructure node: prerequisites, the installers, and removing services safely.',
            'file' => 'installation-guide.md',
            'admin_only' => true,
        ],
    ];

    /**
     * @return list<array{slug: string, title: string, description: string, admin_only: bool}>
     */
    public function guides(bool $includeAdminOnly): array
    {
        $guides = [];

        foreach (self::GUIDES as $slug => $guide) {
            if ($guide['admin_only'] && ! $includeAdminOnly) {
                continue;
            }

            $guides[] = [
                'slug' => $slug,
                'title' => $guide['title'],
                'description' => $guide['description'],
                'admin_only' => $guide['admin_only'],
            ];
        }

        return $guides;
    }

    /**
     * @return array{slug: string, title: string, description: string, admin_only: bool, intro: string, chapters: list<array{slug: string, number: int, title: string}>}|null
     */
    public function guide(string $slug): ?array
    {
        if (! array_key_exists($slug, self::GUIDES)) {
            return null;
        }

        $guide = self::GUIDES[$slug];
        $markdown = $this->markdown($guide['file']);
        $sections = $this->splitIntoChapters($markdown);

        return [
            'slug' => $slug,
            'title' => $guide['title'],
            'description' => $guide['description'],
            'admin_only' => $guide['admin_only'],
            'intro' => $sections['intro'],
            'chapters' => array_map(fn (array $chapter): array => [
                'slug' => $chapter['slug'],
                'number' => $chapter['number'],
                'title' => $chapter['title'],
            ], $sections['chapters']),
        ];
    }

    /**
     * @return array{number: int, slug: string, title: string, body: string, previous: array{slug: string, title: string}|null, next: array{slug: string, title: string}|null}|null
     */
    public function chapter(string $guideSlug, string $chapterSlug): ?array
    {
        if (! array_key_exists($guideSlug, self::GUIDES)) {
            return null;
        }

        $markdown = $this->markdown(self::GUIDES[$guideSlug]['file']);
        $chapters = $this->splitIntoChapters($markdown)['chapters'];

        $index = null;

        foreach ($chapters as $i => $chapter) {
            if ($chapter['slug'] === $chapterSlug) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return null;
        }

        $toSummary = fn (array $chapter): array => ['slug' => $chapter['slug'], 'title' => $chapter['title']];

        return [
            'number' => $chapters[$index]['number'],
            'slug' => $chapters[$index]['slug'],
            'title' => $chapters[$index]['title'],
            'body' => $chapters[$index]['body'],
            'previous' => $index > 0 ? $toSummary($chapters[$index - 1]) : null,
            'next' => $index < count($chapters) - 1 ? $toSummary($chapters[$index + 1]) : null,
        ];
    }

    private function markdown(string $file): string
    {
        return File::get(resource_path('docs/'.$file));
    }

    /**
     * @return array{intro: string, chapters: list<array{number: int, slug: string, title: string, body: string}>}
     */
    private function splitIntoChapters(string $markdown): array
    {
        preg_match_all('/^## Chapter (\d+): (.+)$/m', $markdown, $matches, PREG_OFFSET_CAPTURE);

        $headingCount = count($matches[0]);
        $firstHeadingOffset = $headingCount > 0 ? $matches[0][0][1] : strlen($markdown);

        $intro = substr($markdown, 0, $firstHeadingOffset);
        $intro = preg_replace('/^#\s+.+\n/', '', $intro, 1) ?? $intro;
        $intro = $this->trimTrailingRule($intro);

        $chapters = [];

        for ($i = 0; $i < $headingCount; $i++) {
            $number = (int) $matches[1][$i][0];
            $title = trim($matches[2][$i][0]);
            $bodyStart = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $bodyEnd = $i + 1 < $headingCount ? $matches[0][$i + 1][1] : strlen($markdown);
            $body = $this->trimTrailingRule(substr($markdown, $bodyStart, $bodyEnd - $bodyStart));

            $chapters[] = [
                'number' => $number,
                'slug' => 'chapter-'.$number,
                'title' => $title,
                'body' => $body,
            ];
        }

        return ['intro' => $intro, 'chapters' => $chapters];
    }

    private function trimTrailingRule(string $section): string
    {
        return trim(preg_replace('/\n---\s*$/', '', trim($section)) ?? $section);
    }
}
