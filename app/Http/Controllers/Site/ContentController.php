<?php

namespace App\Http\Controllers\Site;

use App\Enums\ContentKind;
use App\Http\Controllers\Controller;
use App\Services\ContentService;
use App\Services\CurrentWebsite;
use Illuminate\Contracts\View\View;

/**
 * Vitrin — yayındaki yazı ve sayfalar (CMS Core). Yalnızca PUBLISHED ve
 * yayın tarihi geçmiş içerik döner; taslak/zamanlanmış içerik 404'tür.
 */
class ContentController extends Controller
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly CurrentWebsite $website,
    ) {}

    public function posts(): View
    {
        $website = $this->website->get();

        return view('site.posts', [
            'posts' => $this->contents->livePosts($website, 50),
            'categories' => $this->contents->categories($website),
        ]);
    }

    /** Kategori sayfası (faz 18): kategori serbest metin, eşleme slug ile. */
    public function category(string $category): View
    {
        $website = $this->website->get();
        $categories = $this->contents->categories($website);

        abort_unless(isset($categories[$category]), 404);

        return view('site.category', [
            'categorySlug' => $category,
            'categoryName' => $categories[$category]['name'],
            'categories' => $categories,
            'posts' => $this->contents->livePostsInCategory($website, $category),
        ]);
    }

    public function post(string $slug): View
    {
        $content = $this->contents->findLive($this->website->get(), ContentKind::POST, $slug);

        abort_if($content === null, 404);

        return view('site.content', [
            'content' => $content,
            'isPost' => true,
            'related' => $this->contents->relatedPosts($content),
        ]);
    }

    public function page(string $slug): View
    {
        $content = $this->contents->findLive($this->website->get(), ContentKind::PAGE, $slug);

        abort_if($content === null, 404);

        return view('site.content', ['content' => $content, 'isPost' => false]);
    }
}
