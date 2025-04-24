<?php

namespace Prism\Bedrock;

use Illuminate\Support\ServiceProvider;

class BedrockServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerWithPrism();
    }

    protected function registerWithPrism(): void
    {
        $this->app->get('prism-manager')->extend(Bedrock::KEY, fn ($app, $config): \Prism\Bedrock\Bedrock => new Bedrock(
            apiKey: $config['api_key'],
            apiSecret: $config['api_secret'],
            sessionToken: $config['session_token'] ?? null,
            region: $config['region']
        ));
    }
}
