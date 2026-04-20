<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment($app)
    {
        tap($app['config'], function (Repository $config): void {
            $config->set('prism.providers.bedrock', [
                'api_key' => env('PRISM_BEDROCK_API_KEY', 'test-api-key'),
                'api_secret' => env('PRISM_BEDROCK_API_SECRET', 'test-api-secret'),
                'region' => env('PRISM_BEDROCK_REGION', 'us-west-2'),
            ]);
        });
    }
}
