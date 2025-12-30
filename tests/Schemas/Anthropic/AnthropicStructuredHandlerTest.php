<?php

declare(strict_types=1);

namespace Tests\Schemas\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = (new Prism)->structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
    ]);
    expect($response->structured['weather'])->toBeString();
    expect($response->structured['game_time'])->toBeString();
    expect($response->structured['coat_required'])->toBeBool();
});

it('uses custom jsonModeMessage when provided via providerOptions', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $customMessage = 'Please return a JSON response using this custom format instruction';

    (new Prism)->structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withProviderOptions([
            'jsonModeMessage' => $customMessage,
        ])
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    Http::assertSent(function (Request $request) use ($customMessage): bool {
        $messages = $request->data()['messages'] ?? [];
        $lastMessage = end($messages);

        return isset($lastMessage['content'][0]['text']) &&
               str_contains((string) $lastMessage['content'][0]['text'], $customMessage);
    });
});

it('uses default jsonModeMessage when no custom message is provided', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $defaultMessage = 'Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema:';

    (new Prism)->structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    Http::assertSent(function (Request $request) use ($defaultMessage): bool {
        $messages = $request->data()['messages'] ?? [];
        $lastMessage = end($messages);

        return isset($lastMessage['content'][0]['text']) &&
               str_contains((string) $lastMessage['content'][0]['text'], $defaultMessage);
    });
});

it('does not remove 0 values from payloads', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    (new Prism)->structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withProviderOptions([
            'guardRailConfig' => null,
        ])
        ->withMaxTokens(2048)
        ->usingTemperature(0)
        ->asStructured();

    Http::assertSent(function (Request $request): bool {
        expect($request->data())->toMatchArray([
            'temperature' => 0,
        ]);

        return true;
    });
});
