<?php

use Illuminate\Support\Facades\Http;
use Prism\Bedrock\Bedrock;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Prism;

it('registers itself as a provider with prism', function (): void {
    $pendingRequest = Prism::text()->using('bedrock', 'test-model');

    expect($pendingRequest->provider())->toBeInstanceOf(Bedrock::class);
});

it('throws an exception for embeddings with Anthropic apiSchema', function (): void {
    Http::fake();
    Http::preventStrayRequests();

    Prism::embeddings()
        ->using('bedrock', 'test-model')
        ->withProviderOptions(['apiSchema' => BedrockSchema::Anthropic])
        ->fromInput('Hello world')
        ->asEmbeddings();
})->throws(PrismException::class, 'Prism Bedrock does not support embeddings for the anthropic apiSchema.');

it('throws an exception for embeddings with converse apiSchema', function (): void {
    Http::fake();
    Http::preventStrayRequests();

    Prism::embeddings()
        ->using('bedrock', 'test-model')
        ->fromInput('Hello world')
        ->asEmbeddings();
})->throws(PrismException::class, 'Prism Bedrock does not support embeddings for the converse apiSchema.');
