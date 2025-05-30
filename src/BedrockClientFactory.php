<?php

namespace Prism\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use GuzzleHttp\HandlerStack;

class BedrockClientFactory
{
    public function make(?HandlerStack $handler = null): BedrockRuntimeClient
    {
        $config = [
            'region' => config('prism.providers.bedrock.region', 'eu-central-1'),
            'version' => config('prism.providers.bedrock.version', 'latest'),
            'credentials' => [
                'key' => config('prism.providers.bedrock.api_key', ''),
                'secret' => config('prism.providers.bedrock.api_secret', ''),
            ],
        ];

        if ($handler instanceof \GuzzleHttp\HandlerStack) {
            $config['http'] = ['handler' => $handler];
        }

        return new BedrockRuntimeClient($config);
    }
}
