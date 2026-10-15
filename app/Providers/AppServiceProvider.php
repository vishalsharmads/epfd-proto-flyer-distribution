<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Areas\GeoscapeBuildingsLetterboxSource;
use App\Domain\Areas\GnafLetterboxSource;
use App\Domain\Areas\LetterboxSource;
use App\Domain\Gnaf\AddressClassifier;
use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
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
    }

    public function boot(): void
    {
        //
    }
}
