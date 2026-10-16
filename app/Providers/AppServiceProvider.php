<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Areas\GeoscapeBuildingsLetterboxSource;
use App\Domain\Areas\GnafLetterboxSource;
use App\Domain\Areas\LetterboxSource;
use App\Domain\Gnaf\AddressClassifier;
use App\Domain\Payments\MockGateway;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\StripeGateway;
use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
use App\Domain\Settings\Settings;
use App\Models\Address;
use App\Models\Artwork;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CampaignArea;
use App\Models\ContentPage;
use App\Models\CreditLedger;
use App\Models\DesignAsset;
use App\Models\DesignOrder;
use App\Models\GnafRelease;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PickupPoint;
use App\Models\PrintOption;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use App\Models\PromoCode;
use App\Models\RateCard;
use App\Models\Run;
use App\Models\RunGpsPoint;
use App\Models\SavedArea;
use App\Models\SavedCard;
use App\Models\Suburb;
use App\Models\SupportThread;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AddressClassifier::class, fn () => AddressClassifier::fromConfig());
        $this->app->bind(LetterboxSource::class, fn ($app) => match (config('epfd.estimator.source')) {
            'geoscape_buildings' => $app->make(GeoscapeBuildingsLetterboxSource::class),
            default => $app->make(GnafLetterboxSource::class),
        });
        $this->app->scoped(PricingService::class, fn () => PricingFactory::make());
        $this->app->singleton(PaymentGateway::class, fn () => config('epfd.payments.gateway') === 'stripe' && config('epfd.payments.stripe_secret')
            ? new StripeGateway
            : new MockGateway);
    }

    public function boot(): void
    {
        Settings::apply();
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::preventLazyLoading(false);

        Relation::enforceMorphMap([
            'campaign' => Campaign::class,
            'print_order' => PrintOrder::class,
            'design_order' => DesignOrder::class,
            'design_asset' => DesignAsset::class,
            'payment' => Payment::class,
            'business' => Business::class,
            'run' => Run::class,
            'user' => User::class,
            'invoice' => Invoice::class,
            'promo_code' => PromoCode::class,
            'rate_card' => RateCard::class,
            'support_thread' => SupportThread::class,
            'artwork' => Artwork::class,
            'suburb' => Suburb::class,
            'pickup_point' => PickupPoint::class,
            'content_page' => ContentPage::class,
            'campaign_area' => CampaignArea::class,
            'print_product' => PrintProduct::class,
            'print_option' => PrintOption::class,
            'saved_card' => SavedCard::class,
            'saved_area' => SavedArea::class,
            'credit_ledger' => CreditLedger::class,
            'message' => Message::class,
            'gnaf_release' => GnafRelease::class,
            'address' => Address::class,
            'run_gps_point' => RunGpsPoint::class,
        ]);

        // Super admins bypass every permission check.
        Gate::before(fn (User $user) => $user->hasRole('super_admin') ? true : null);

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('estimate', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => [
            Limit::perMinute(10)->by($r->ip()),
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
        ]);
        RateLimiter::for('payments', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
    }
}
