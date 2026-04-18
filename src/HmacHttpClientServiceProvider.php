<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;

final class HmacHttpClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/hmac-http-client.php',
            'hmac-http-client'
        );

        $this->app->singleton(ClockInterface::class, SystemClock::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/hmac-http-client.php' => config_path('hmac-http-client.php'),
        ], 'hmac-http-client-config');

        $this->registerHttpMacro();
    }

    private function registerHttpMacro(): void
    {
        // Http::macro rebinds $this to the HTTP Factory inside the closure, so
        // capture the container separately to resolve the clock at call time.
        $app = $this->app;

        Http::macro('hmac', function (string $service) use ($app) {
            $config = ServiceConfig::forService($service);

            $middleware = new SignedRequestMiddleware(
                keyId: $config->keyId,
                signer: $config->signer,
                clock: $app->make(ClockInterface::class),
            );

            /** @var Factory $this */
            return $this->createPendingRequest()
                ->baseUrl($config->baseUrl)
                ->withMiddleware($middleware)
                ->acceptJson()
                ->asJson();
        });
    }
}
