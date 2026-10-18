<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\PrintCategory;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Print categories (Flyers, Brochures, …) used to group products in the customer catalogue. */
class PrintCategoryController extends AdminController
{
    public function store(Request $request): RedirectResponse
    {
        $category = PrintCategory::create($this->validated($request, new PrintCategory));
        Audit::log('print_category.created', $category, $category->only('slug', 'name', 'icon', 'sort', 'is_active'));

        return back()->with('success', "Category “{$category->name}” added.");
    }

    public function update(Request $request, PrintCategory $category): RedirectResponse
    {
        $before = $category->only('slug', 'name', 'icon', 'sort', 'is_active');
        $category->update($this->validated($request, $category));
        Audit::log('print_category.updated', $category, ['before' => $before, 'after' => $category->only('slug', 'name', 'icon', 'sort', 'is_active')]);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(PrintCategory $category): RedirectResponse
    {
        if ($category->products()->exists()) {
            return back()->with('error', 'Move or delete this category’s products first, or hide the category instead.');
        }
        Audit::log('print_category.deleted', $category, ['slug' => $category->slug, 'name' => $category->name]);
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, PrintCategory $category): array
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')), '_')]);

        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'slug' => ['required', 'alpha_dash', 'max:40', Rule::unique('print_categories', 'slug')->ignore($category->id)],
            'icon' => ['required', Rule::in(PrintCategory::ICONS)],
            'sort' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['boolean'],
        ]);
    }
}
