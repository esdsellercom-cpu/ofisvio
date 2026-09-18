<?php

namespace App\Http\Controllers\Site;

use App\Enums\ContentKind;
use App\Http\Controllers\Controller;
use App\Seo\GeoAnswers;
use App\Services\ContentService;
use App\Services\CurrentWebsite;
use App\Services\EntityGraphService;
use App\Services\LandingPageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Vitrin — yayındaki yazı ve sayfalar (CMS Core). Yalnızca PUBLISHED ve
 * yayın tarihi geçmiş içerik döner; taslak/zamanlanmış içerik 404'tür.
 */
class ContentController extends Controller
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly CurrentWebsite $website,
        private readonly LandingPageService $landing,
        private readonly EntityGraphService $entities,
    ) {}

    /** İmzalı önizleme (faz 48): durumdan bağımsız, sitenin kendi şablonuyla; composer noindex basar. */
    public function preview(Request $request, int $content): View
    {
        $record = $this->contents->findForPreview($content, $request->boolean('draft'));

        abort_if($record === null, 404);

        return view('site.content', ['content' => $record, 'isPost' => $record->kind === ContentKind::POST, 'children' => collect(), 'related' => collect(), 'preview' => true]);
    }

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
            // Varlık ilişkileri (faz 60b): yazının bağlı olduğu hizmet/lokasyon bağlantıları.
            'entities' => $this->entities->entitiesOf($this->website->get(), $content),
        ]);
    }

    public function page(string $slug): View
    {
        $content = $this->contents->findLivePage($this->website->get(), $slug);

        abort_if($content === null, 404);

        return view('site.content', ['content' => $content, 'isPost' => false, 'children' => $this->contents->liveChildren($content), 'entities' => $this->entities->entitiesOf($this->website->get(), $content)]);
    }

    /** Alt sayfa (faz 29): /ebeveyn/sayfa. */
    public function childPage(string $parent, string $slug): View
    {
        $content = $this->contents->findLivePage($this->website->get(), $slug, $parent);

        if ($content === null) {
            // Programatik hizmet × şehir sayfası (faz 60b): /{hizmet-slug}/{sehir-slug}.
            $landing = $this->landing->findLive($this->website->get(), $parent, $slug);

            abort_if($landing === null, 404);

            return view('site.landing', [
                'landing' => $landing,
                'service' => null,
                'sections' => GeoAnswers::sections($landing->service->answers),
                'siblings' => $this->landing->live($this->website->get(), $landing->service_id)->where('id', '!=', $landing->id),
                'articles' => $this->entities->contentsAbout($this->website->get(), 'service', $landing->service_id),
            ]);
        }

        return view('site.content', ['content' => $content, 'isPost' => false, 'children' => collect(), 'entities' => $this->entities->entitiesOf($this->website->get(), $content)]);
    }
}
