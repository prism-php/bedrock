<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\BedrockRuntimeClientMockResponse;
use Tests\Fixtures\FixtureResponse;

it('can generate text with a basic stream', function (): void {

    $fakeResult = FixtureResponse::fakeConverseStream('converse/stream-basic-text');

    BedrockRuntimeClientMockResponse::mock($fakeResult);

    $response = Prism::text()
        ->using('bedrock', 'anthropic.claude-3-5-sonnet-20240620-v1:0')
        ->withMessages([new UserMessage('Who are you?')])
        ->asStream();

    $text = '';
    $chunks = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();
    expect(end($chunks)->finishReason)->toBe(FinishReason::Stop);
});
