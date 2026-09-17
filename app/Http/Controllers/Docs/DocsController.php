<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use App\Services\DocsCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves the app's own static documentation (resources/docs/*.md via DocsCatalog): one index of
 * guides, one chapter index per guide, one page per chapter -- never one large page. The
 * User Guide is for every logged-in user; the Admin and Installation Guides are for a provider
 * admin only (isProviderAdmin), the same platform-admin boundary the sidebar's own Accounts/
 * Nodes/Backups/Packages/Roles items already use, since both guides document admin-only screens
 * and server-side install steps an end-user has no reason to see and no ability to act on.
 */
class DocsController extends Controller
{
    public function __construct(private readonly DocsCatalog $docs) {}

    public function index(Request $request): Response
    {
        return Inertia::render('docs/index', [
            'guides' => $this->docs->guides($request->user()->isProviderAdmin()),
        ]);
    }

    public function show(Request $request, string $guide): Response
    {
        $data = $this->docs->guide($guide);
        $isProviderAdmin = $request->user()->isProviderAdmin();

        abort_if($data === null, 404);
        abort_if($data['admin_only'] && ! $isProviderAdmin, 403);

        return Inertia::render('docs/show', [
            'guide' => $data,
            // The top-of-page switcher to the other guides: only ever the ones this user is
            // themselves allowed to open, mirroring index()'s own filtering exactly, so an
            // end-user editing the URL by hand still never sees a link into the Admin/
            // Installation guides.
            'guides' => $this->docs->guides($isProviderAdmin),
        ]);
    }

    public function chapter(Request $request, string $guide, string $chapter): Response
    {
        $guideData = $this->docs->guide($guide);
        $isProviderAdmin = $request->user()->isProviderAdmin();

        abort_if($guideData === null, 404);
        abort_if($guideData['admin_only'] && ! $isProviderAdmin, 403);

        $chapterData = $this->docs->chapter($guide, $chapter);

        abort_if($chapterData === null, 404);

        return Inertia::render('docs/chapter', [
            'guide' => $guideData,
            'chapter' => $chapterData,
            'guides' => $this->docs->guides($isProviderAdmin),
        ]);
    }
}
