<?php

declare(strict_types=1);

use App\Domain\Pricing\PricingFactory;
use App\Exceptions\DomainProblem;
use App\Models\PrintCategory;
use App\Models\PrintOption;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** @return array<string, mixed> */
function productPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Door Hangers '.uniqid(),
        'category' => 'flyer',
        'description' => 'Hang them on every handle.',
        'size_label' => '100 × 230 mm',
        'width_mm' => 100,
        'height_mm' => 230,
        'bleed_mm' => 3,
        'gsm_options' => [250, 350],
        'sides_options' => [1, 2],
        'finish_options' => ['250' => ['matt', 'gloss'], '350' => ['matt']],
        'quantity_options' => [500, 1000],
        'distributable' => false,
        'turnaround_days' => 4,
        'sort' => 50,
        'is_active' => true,
    ], $overrides);
}

function priceEveryCell(PrintProduct $product, int $hc = 500): void
{
    test()->put("/admin/rate-card/matrix/{$product->id}", [
        'cells' => $product->options()->get()->map(fn (PrintOption $o) => ['id' => $o->id, 'unit_price_hc' => $hc])->all(),
    ])->assertRedirect();
}

it('creates a product with an unpriced matrix and keeps it off sale until every option is priced', function () {
    $this->actingAs(staffUser('operations'));

    $this->post('/admin/catalogue/print', productPayload(['name' => 'Door Hangers']))->assertRedirect()->assertSessionHas('warning');

    $product = PrintProduct::where('name', 'Door Hangers')->firstOrFail();
    expect($product->slug)->toBe('door-hangers')
        ->and($product->is_active)->toBeFalse()
        ->and($product->options()->count())->toBe(2 * 2 * 2)
        ->and($product->options()->whereNull('unit_price_hc')->count())->toBe(8);

    $this->post("/admin/catalogue/print/{$product->id}/toggle")->assertSessionHas('error');
    expect($product->fresh()->is_active)->toBeFalse();

    $this->actingAs(staffUser('finance'));
    priceEveryCell($product, 1234);
    $this->post("/admin/catalogue/print/{$product->id}/toggle")->assertSessionHas('success');

    expect($product->fresh())->is_active->toBeTrue()->from_price_cents->toBe(13)
        ->and(PrintProduct::forSale()->pluck('id'))->toContain($product->id);
});

it('never sells an unpriced combination as free', function () {
    $product = PrintProduct::factory()->create(['gsm_options' => [150], 'sides_options' => [1], 'quantity_options' => [100]]);
    PrintOption::create(['print_product_id' => $product->id, 'gsm' => 150, 'sides' => 1, 'quantity' => 100, 'unit_price_hc' => null]);
    PricingFactory::forgetMatrix();

    expect(fn () => PricingFactory::make()->print(['product_id' => $product->id, 'gsm' => 150, 'sides' => 1, 'quantity' => 100]))
        ->toThrow(DomainProblem::class);
});

it('adds and removes matrix cells when options change, keeping existing prices', function () {
    $this->actingAs(staffUser('operations'));
    $this->post('/admin/catalogue/print', productPayload(['name' => 'Sync Test']));
    $product = PrintProduct::where('name', 'Sync Test')->firstOrFail();
    $this->actingAs(staffUser('finance'));
    priceEveryCell($product, 900);

    $this->actingAs(staffUser('operations'))
        ->put("/admin/catalogue/print/{$product->id}", productPayload(['name' => 'Sync Test', 'gsm_options' => [250], 'finish_options' => ['250' => ['matt']], 'quantity_options' => [500, 1000, 2500]]))
        ->assertSessionHas('warning');

    $cells = $product->options()->get();
    expect($cells)->toHaveCount(1 * 2 * 3)
        ->and($cells->where('gsm', 350))->toBeEmpty()
        ->and($cells->whereNotNull('unit_price_hc')->pluck('unit_price_hc')->unique()->all())->toBe([900])
        ->and($cells->whereNull('unit_price_hc')->pluck('quantity')->unique()->all())->toBe([2500])
        ->and($product->fresh()->finish_options)->toBe(['250' => ['matt']]);
});

it('stores an uploaded product image on the public disk', function () {
    Storage::fake('public');
    $this->actingAs(staffUser('operations'));

    $this->post('/admin/catalogue/print', productPayload(['name' => 'With Image', 'image' => UploadedFile::fake()->image('hanger.jpg', 800, 600)]))->assertRedirect();

    $product = PrintProduct::where('name', 'With Image')->firstOrFail();
    Storage::disk('public')->assertExists($product->image_path);
    expect($product->imageUrl())->toContain('/storage/catalogue/print/');
});

it('requires a finish for every paper weight and a material for distributable products', function () {
    $this->actingAs(staffUser('operations'))
        ->post('/admin/catalogue/print', productPayload(['finish_options' => ['250' => ['matt']], 'distributable' => true]))
        ->assertSessionHasErrors(['finish_options', 'distribution_material']);
});

it('hides products in hidden categories from customers and blocks ordering them', function () {
    $category = PrintCategory::create(['slug' => 'hangers', 'name' => 'Door hangers', 'icon' => 'tag', 'sort' => 99, 'is_active' => false]);
    $product = PrintProduct::factory()->create(['category' => $category->slug]);

    expect(PrintProduct::forSale()->pluck('id'))->not->toContain($product->id);

    $this->actingAs(businessUser())->post('/app/print', ['product_id' => $product->id])->assertNotFound();
});

it('enforces the sides and finishes a product offers', function () {
    $product = PrintProduct::forSale()->where('slug', 'magnets')->firstOrFail();
    expect($product->sides())->toBe([1]);
    $user = businessUser();
    $this->actingAs($user)->post('/app/print', ['product_id' => $product->id]);
    $order = PrintOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();

    $this->patchJson("/app/print/{$order->id}", ['sides' => 2])->assertStatus(422)->assertJsonPath('errors.sides.0', 'This product is single-sided only.');
    $this->patchJson("/app/print/{$order->id}", ['finish' => 'gloss'])->assertStatus(422)->assertJsonPath('errors.finish.0', 'That finish is not available for this paper weight.');
});

it('only deletes hidden, never-ordered products and empty categories', function () {
    $this->actingAs(staffUser('operations'));
    $ordered = PrintProduct::where('slug', 'a5-flyers')->firstOrFail();
    PrintOrder::create(['business_id' => businessUser()->business_id, 'reference' => 'PRT-T'.uniqid(), 'status' => 'draft', 'step' => 2, 'print_product_id' => $ordered->id]);
    $ordered->update(['is_active' => false]);

    $this->delete("/admin/catalogue/print/{$ordered->id}")->assertSessionHas('error');
    expect(PrintProduct::find($ordered->id))->not->toBeNull();

    $this->delete('/admin/catalogue/categories/'.PrintCategory::where('slug', 'flyer')->value('id'))->assertSessionHas('error');

    $unused = PrintProduct::factory()->create(['is_active' => false]);
    $this->delete("/admin/catalogue/print/{$unused->id}")->assertRedirect('/admin/catalogue/print');
    expect(PrintProduct::find($unused->id))->toBeNull();
});

it('lets staff add a category and keeps print catalogue admin to catalogue managers', function () {
    $this->actingAs(staffUser('operations'))
        ->post('/admin/catalogue/categories', ['name' => 'Door hangers', 'icon' => 'tag', 'sort' => 10, 'is_active' => true])
        ->assertSessionHas('success');
    expect(PrintCategory::where('slug', 'door_hangers')->exists())->toBeTrue();

    $this->actingAs(staffUser('support'))->get('/admin/catalogue/print')->assertForbidden();
});

it('saves the category display order', function () {
    $ids = PrintCategory::orderBy('sort')->pluck('id')->all();
    $reversed = array_reverse($ids);

    $this->actingAs(staffUser('operations'))->post('/admin/catalogue/categories/reorder', ['ids' => $reversed])->assertSessionHas('success');

    expect(PrintCategory::orderBy('sort')->pluck('id')->all())->toBe($reversed);
});
