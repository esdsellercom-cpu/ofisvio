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

    /** Etiket sayfası (faz 18). */
    public function tag(string $tag): View
    {
        $website = $this->website->get();
        $tags = $this->contents->tags($website);

        abort_unless(isset($tags[$tag]), 404);

        return view('site.tag', [
            'tagSlug' => $tag,
            'tagName' => $tags[$tag]['name'],
            'tags' => $tags,
            'posts' => $this->contents->livePostsWithTag($website, $tag),
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
        $content = $this->contents->findLivePage($this->website->get(), $slug);

        abort_if($content === null, 404);

        return view('site.content', ['content' => $content, 'isPost' => false, 'children' => $this->contents->liveChildren($content)]);
    }

    /** Alt sayfa (faz 29): /ebeveyn/sayfa. */
    public function childPage(string $parent, string $slug): View
    {
        $content = $this->contents->findLivePage($this->website->get(), $slug, $parent);

        abort_if($content === null, 404);

        return view('site.content', ['content' => $content, 'isPost' => false, 'children' => collect()]);
    }
}
