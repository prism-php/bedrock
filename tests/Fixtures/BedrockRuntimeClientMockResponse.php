<?php

namespace Tests\Fixtures;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Prism\Bedrock\BedrockClientFactory;
use Psr\Http\Message\RequestInterface;

class BedrockRuntimeClientMockResponse
{
    public static function mock(Result $fakeResult): void
    {
        $mockHandler = new MockHandler([
            fn (RequestInterface $request, array $options): \GuzzleHttp\Psr7\Response => new Response(200, [
                'Content-Type' => 'application/vnd.amazon.eventstream',
            ], ''),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);

        app()->singleton(BedrockClientFactory::class, fn(): \Prism\Bedrock\BedrockClientFactory => new class($handlerStack, $fakeResult) extends BedrockClientFactory
        {
            public function __construct(
                private readonly HandlerStack $handler,
                private readonly Result $fakeResult
            ) {}

            public function make(?HandlerStack $handler = null): BedrockRuntimeClient
            {
                $client = parent::make($this->handler);

                $client->getHandlerList()->setHandler(
                    fn($command, $request): \GuzzleHttp\Promise\PromiseInterface => $command->getName() === 'ConverseStream'
                        ? Create::promiseFor($this->fakeResult)
                        : Create::promiseFor([])
                );

                return $client;
            }
        });

    }
}
