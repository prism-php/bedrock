<?php

namespace Prism\Bedrock;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Illuminate\Support\ServiceProvider;

class BedrockServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerWithPrism();
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public static function getCredentials(array $config): Credentials
    {
        if ($config['use_default_credential_provider'] ?? false) {
            $provider = CredentialProvider::defaultProvider();
        } else {
            $provider = CredentialProvider::fromCredentials(new Credentials(
                key: $config['api_key'],
                secret: $config['api_secret'],
                token: $config['session_token'] ?? null,
            ));
        }

        return $provider()->wait();
    }

    protected function registerWithPrism(): void
    {
        $this->app->get('prism-manager')->extend(Bedrock::KEY, fn ($app, $config): \Prism\Bedrock\Bedrock => new Bedrock(
            credentials: BedrockServiceProvider::getCredentials($config),
            region: $config['region']
        ));
    }
}
