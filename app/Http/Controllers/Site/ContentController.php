<?php

namespace App\Http\Controllers\Site;

use App\Enums\ContentKind;
use App\Http\Controllers\Controller;
use App\Services\ContentService;
use Illuminate\Contracts\View\View;

/**
 * Vitrin — yayındaki yazı ve sayfalar (CMS Core). Yalnızca PUBLISHED ve
 * yayın tarihi geçmiş içerik döner; taslak/zamanlanmış içerik 404'tür.
 */
class ContentController extends Controller
{
    public function __construct(private readonly ContentService $contents) {}

    public function posts(): View
    {
        $website = $this->contents->defaultWebsiteOrNull();

        return view('site.posts', [
            'posts' => $this->contents->livePosts($website, 50),
        ]);
    }

    public function post(string $slug): View
    {
        $content = $this->contents->findLive($this->contents->defaultWebsiteOrNull(), ContentKind::POST, $slug);

        abort_if($content === null, 404);

        return view('site.content', ['content' => $content, 'isPost' => true]);
    }

    public function page(string $slug): View
    {
        $content = $this->contents->findLive($this->contents->defaultWebsiteOrNull(), ContentKind::PAGE, $slug);

        abort_if($content === null, 404);

        return view('site.content', ['content' => $content, 'isPost' => false]);
    }
}
