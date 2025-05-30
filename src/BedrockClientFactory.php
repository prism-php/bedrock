<?php

namespace Prism\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use GuzzleHttp\HandlerStack;

class BedrockClientFactory
{
    public function make(?HandlerStack $handler = null): BedrockRuntimeClient
    {
        $config = [
            'region' => config('services.bedrock.region', 'eu-central-1'),
            'version' => config('services.bedrock.version', 'latest'),
            'credentials' => [
                'key' => config('services.bedrock.api_key', ''),
                'secret' => config('services.bedrock.api_secret', ''),
            ],
        ];

        if ($handler instanceof \GuzzleHttp\HandlerStack) {
            $config['http'] = ['handler' => $handler];
        }

        return new BedrockRuntimeClient($config);
    }
}
