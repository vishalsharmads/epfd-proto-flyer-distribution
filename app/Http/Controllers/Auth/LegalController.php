<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use Database\Seeders\ContentSeeder;
use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    public function show(string $slug): Response
    {
        if (! ContentPage::where('type', 'legal')->exists()) {
            (new ContentSeeder)->run();
        }
        $page = ContentPage::where('slug', $slug)->where('is_published', true)->firstOrFail();

        return Inertia::render('legal/show', [
            'page' => $page->only('slug', 'title', 'body', 'updated_at'),
            'others' => ContentPage::where('type', 'legal')->where('is_published', true)->orderBy('sort')->get(['slug', 'title']),
        ]);
    }
}
