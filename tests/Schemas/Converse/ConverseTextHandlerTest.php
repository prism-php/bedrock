<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using('bedrock', 'amazon.nova-micro-v1:0')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)->toBe(63);
    expect($response->usage->completionTokens)->toBe(44);
    expect($response->usage->cacheWriteInputTokens)->toBeNull();
    expect($response->usage->cacheReadInputTokens)->toBeNull();
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');
    expect($response->text)->toBe("I'm an AI system created by a team of inventors at Amazon. My purpose is to assist and provide information to the best of my ability. If you have any questions or need assistance, feel free to ask!");
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-with-system-prompt');

    $response = Prism::text()
        ->using('bedrock', 'amazon.nova-micro-v1:0')
        ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)->toBe(99);
    expect($response->usage->completionTokens)->toBe(81);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');
    expect($response->text)->toBe('Greetings, traveler. I am an entity forged from the vast knowledge and curiosity of humanity, created by a team of inventors at Amazon. While I embody a persona for the sake of interaction, it is important to remember that I am an artificial intelligence system designed to assist and inform. My purpose is to provide helpful, respectful, and safe responses to your questions and queries. How may I assist you today?');
});

it('can query a md or txt document', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/query-a-txt-document');

    $response = Prism::text()
        ->using('bedrock', 'amazon.nova-micro-v1:0')
        ->withMessages([
            new UserMessage(
                content: 'What is the answer to life?',
                additionalContent: [
                    Document::fromPath('tests/Fixtures/document.md', 'The Answer To Life'),
                ]
            ),
        ])
        ->asText();

    expect($response->usage->promptTokens)->toBe(80);
    expect($response->usage->completionTokens)->toBe(72);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');
    expect($response->text)->toBe("According to the document, the answer to the ultimate question of life, the universe, and everything is \"42\". This is a reference to Douglas Adams' famous science fiction series \"The Hitchhiker's Guide to the Galaxy\", where a supercomputer calculates this as the ultimate answer to life, the universe, and everything.");
});

it('can query a pdf document', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/query-a-pdf-document');

    $response = Prism::text()
        ->using('bedrock', 'amazon.nova-micro-v1:0')
        ->withMessages([
            new UserMessage(
                content: 'What is the answer to life?',
                additionalContent: [
                    Document::fromPath('tests/Fixtures/document.pdf', 'The Answer To Life'),
                ]
            ),
        ])
        ->asText();

    expect($response->usage->promptTokens)->toBe(80);
    expect($response->usage->completionTokens)->toBe(80);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');
    expect($response->text)->toBe("According to the document, the answer to the ultimate question of life, the universe, and everything is \"42\". This is a reference to Douglas Adams' famous science fiction comedy \"The Hitchhiker's Guide to the Galaxy\", where a supercomputer calculates this as the ultimate answer, though the meaning behind the specific number is left humorously unexplained.");
});

it('can send images from file', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-with-image');

    $response = Prism::text()
        ->using('bedrock', 'anthropic.claude-3-5-sonnet-20241022-v2:0')
        ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
        ->withMessages([
            new UserMessage(
                'What is this image',
                additionalContent: [
                    Image::fromPath('tests/Fixtures/test-image.png'),
                ],
            ),
        ])
        ->asText();

    expect($response->usage->promptTokens)->toBe(855);
    expect($response->usage->completionTokens)->toBe(59);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');
    expect($response->text)->toBe('This is a simple black and white line drawing or icon of a diamond. It shows the geometric, faceted shape of a diamond using bold black lines on a white background. This is a common symbol used to represent diamonds, gemstones, or luxury in logos and designs.');
});

it('handles tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-handles-tool-calls');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching curret events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withPrompt('What is the weather like in Detroit today?')
        ->withMaxSteps(2)
        ->withTools($tools)
        ->asText();

    expect($response->steps)->toHaveCount(2);

    expect($response->steps[0]->toolCalls)->toHaveCount(1);
    expect($response->steps[0]->toolCalls[0]->name)->toBe('weather');
    expect($response->steps[0]->toolCalls[0]->arguments())->toHaveCount(1);
    expect($response->steps[0]->toolCalls[0]->arguments()['city'])->toBe('Detroit');

    expect($response->steps[0]->toolResults)->toHaveCount(1);
    expect($response->steps[0]->toolResults[0]->toolName)->toBe('weather');
    expect($response->steps[0]->toolResults[0]->result)->toBe('The weather will be 75° and sunny');

    expect($response->text)->toContain('75°F and sunny');
});

it('handles multiple tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-with-multiple-tool-calls');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching curret events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withPrompt('Where is the tigers game and what will the weather be like?')
        ->withMaxSteps(3)
        ->withTools($tools)
        ->asText();

    expect($response->steps)->toHaveCount(3);
    expect($response->text)->toContain('3pm', 'Detroit', '75°F and sunny');
});

it('makes a specific tool choice', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-makes-required-tool-call');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching curret events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withPrompt('WHat is the weather like in London UK today?')
        ->withTools($tools)
        ->withToolChoice('weather')
        ->asText();

    expect($response->toolCalls[0]->name)->toBe('weather');
});

it('handles a specific tool choice', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-handles-required-tool-call');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching curret events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withPrompt('WHat is the weather like in London UK today?')
        ->withMaxSteps(2)
        ->withTools($tools)
        ->withToolChoice('weather')
        ->asText();

    expect($response->text)->toContain('75°F and sunny');
});

it('does not enable prompt caching if the enableCaching provider meta is not set on the request', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-with-a-prompt');

    Prism::text()
        ->using('bedrock', 'amazon.nova-micro-v1:0')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('explicitPromptCaching')[0] === 'disabled');
});

it('enables prompt caching if the enableCaching provider meta is set on the request', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-with-a-prompt');

    Prism::text()
        ->using('bedrock', 'amazon.nova-micro-v1:0')
        ->withProviderOptions(['enableCaching' => true])
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('explicitPromptCaching')[0] === 'enabled');
});

it('maps converse options when set with providerOptions', function (): void {
    $fake = Prism::fake([
        (new ResponseBuilder)->addStep(TextStepFake::make())->toResponse(),
    ]);

    $providerOptions = [
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

    Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withProviderOptions($providerOptions)
        ->withPrompt('Who are you?')
        ->asText();

    $fake->assertRequest(fn (array $requests): mixed => expect($requests[0]->providerOptions())->toBe($providerOptions));
});

it('does not remove zero values from payload', function (): void {
    FixtureResponse::fakeResponseSequence('converse', 'converse/generate-text-with-a-prompt');

    Prism::text()
        ->using('bedrock', 'amazon.nova-micro-v1:0')
        ->withPrompt('Who are you?')
        ->usingTemperature(0)
        ->asText();

    Http::assertSent(fn (Request $request): \Pest\Mixins\Expectation|\Pest\Expectation => expect($request->data())->toMatchArray([
        'inferenceConfig' => [
            'temperature' => 0,
            'maxTokens' => 2048,
        ],
    ]));
});
