<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Testing\StructuredStepFake;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/structured');

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

    $response = Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
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

    expect($response->usage->promptTokens)->toBe(223);
    expect($response->usage->completionTokens)->toBe(35);
    expect($response->usage->cacheWriteInputTokens)->toBeNull();
    expect($response->usage->cacheReadInputTokens)->toBeNull();
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');
});

it('maps converse options when set with providerOptions', function (): void {
    $fake = Prism::fake([
        (new ResponseBuilder)->addStep(
            StructuredStepFake::make()->withText(json_encode(['foo' => 'bar']))
        )->toResponse(),
    ]);

    $providerOptions = [
        'apiSchema' => BedrockSchema::Converse,
        'additionalModelRequestFields' => [
            'anthropic_beta' => ['output-128k-2025-02-19'],
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 16000],
        ],
        'additionalModelResponseFieldPaths' => ['foo.bar', 'baz.qux'],
        'guardrailConfig' => ['rules' => ['no-violence']],
        'performanceConfig' => ['timeoutMs' => 2000],
        'promptVariables' => ['userName' => 'Alice'],
        'requestMetadata' => ['requestId' => 'abc-123'],
    ];

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

    $response = Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withProviderOptions($providerOptions)
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    $fake->assertRequest(fn (array $requests): mixed => expect($requests[0]->providerOptions())->toBe($providerOptions));
});

it('uses custom jsonModeMessage when provided via providerOptions', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/structured');

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

    Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withProviderOptions([
            'apiSchema' => BedrockSchema::Converse,
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
    FixtureResponse::fakeResponseSequence('converse', 'converse/structured');

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

    Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withProviderOptions([
            'apiSchema' => BedrockSchema::Converse,
        ])
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
    FixtureResponse::fakeResponseSequence('converse', 'converse/structured');

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

    Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withProviderOptions([
            'apiSchema' => BedrockSchema::Converse,
            'guardRailConfig' => null,
        ])
        ->withMaxTokens(2048)
        ->usingTemperature(0)
        ->asStructured();

    Http::assertSent(function (Request $request): bool {
        expect($request->data())->toMatchArray([
            'inferenceConfig' => [
                'maxTokens' => 2048,
                'temperature' => 0,
            ],
        ])
            ->not()
            ->toHaveKey('guardRailConfig');

        return true;
    });
});
