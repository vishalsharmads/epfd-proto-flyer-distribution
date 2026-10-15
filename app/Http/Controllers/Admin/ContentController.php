<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\ContentPage;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContentController extends AdminController
{
    public function index(Request $request): Response
    {
        $type = $request->input('type') === 'legal' ? 'legal' : 'faq';

        return Inertia::render('admin/content/index', [
            'type' => $type,
            'pages' => ContentPage::where('type', $type)->orderBy('sort')->orderBy('id')->get(),
            'counts' => ContentPage::selectRaw('type, count(*) c')->groupBy('type')->pluck('c', 'type'),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ContentPage $page = null): array
    {
        $v = $request->validate([
            'type' => ['required', 'in:faq,legal'],
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('content_pages', 'slug')->ignore($page?->id)],
            'body' => ['required', 'string', 'max:200000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['boolean'],
        ]);
        $v['slug'] = ($v['slug'] ?? null) ?: Str::slug(Str::limit($v['title'], 80, '')).($v['type'] === 'faq' ? '-faq' : '');
        $v['sort'] = $v['sort'] ?? 0;

        return $v;
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $this->validated($request);
        if (ContentPage::where('slug', $v['slug'])->exists()) {
            $v['slug'] .= '-'.Str::lower(Str::random(4));
        }
        $page = ContentPage::create($v);
        Audit::log('content.created', $page, ['title' => $page->title, 'type' => $page->type]);

        return back()->with('success', 'Page created.');
    }

    public function update(Request $request, ContentPage $page): RedirectResponse
    {
        $before = $page->only(['title', 'slug', 'body', 'is_published', 'sort']);
        $page->update($this->validated($request, $page));
        Audit::log('content.updated', $page, ['before' => $before, 'after' => $page->only(['title', 'slug', 'body', 'is_published', 'sort'])]);

        return back()->with('success', 'Page saved.');
    }

    public function destroy(ContentPage $page): RedirectResponse
    {
        Audit::log('content.deleted', $page, ['title' => $page->title]);
        $page->delete();

        return back()->with('success', 'Page deleted.');
    }
}
