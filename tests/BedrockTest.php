<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

it('URL encodes model names with special characters like inference profiles', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'converse/generate-text-with-a-prompt');

    // Test with an inference profile ARN that contains colons and slashes
    $inferenceProfile = 'arn:aws:bedrock:us-east-1:123456789012:application-inference-profile/abc123def456';

    Prism::text()
        ->using('bedrock', $inferenceProfile)
        ->withPrompt('Hello')
        ->asText();

    Http::assertSent(function (Request $request) use ($inferenceProfile): bool {
        // The URL should contain encoded colons (%3A) and slashes (%2F)
        return str_contains($request->url(), '%3A')
            && str_contains($request->url(), '%2F')
            && str_contains($request->url(), rawurlencode($inferenceProfile));
    });
});
