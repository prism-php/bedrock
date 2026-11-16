<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NumberFormatter;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Enums\Citations\CitationSourcePositionType;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

it('streams output', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-basic-text');

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    $finalEvent = end($events);

    expect($finalEvent->finishReason)->toBe(FinishReason::Stop);

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream'));

    expect($text)
        ->toBe('I am an AI assistant called Claude. I was created by Anthropic to be helpful, '.
            'harmless, and honest. I don\'t have a physical body or avatar - I\'m a language '.
            'model trained to engage in conversation and help with tasks. How can I assist you today?');
});

it('can return usage with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-basic-text-with-cache-usage');

    // Read a large system prompt from cache
    // Write a conversation to cache

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withSystemPrompt(
            (new SystemMessage(
                collect(range(1, 1000))
                    ->map(fn ($i): string|false => NumberFormatter::create('en', NumberFormatter::SPELLOUT)->format($i))
                    ->implode(' ')
            ))
                ->withProviderOptions(['cacheType' => 'default'])
        )
        ->withMessages([
            new UserMessage('Who are you?'),
            (new AssistantMessage('Hi I\'m Nova'))
                ->withProviderOptions(['cacheType' => 'default']),
            new UserMessage('Nice to meet you Nova'),
        ])
        ->asStream();

    $text = '';
    $event = [];

    foreach ($response as $chunk) {
        $event[] = $chunk;
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect((array) end($event)->usage)->toBe([
        'promptTokens' => 67,
        'completionTokens' => 48,
        'cacheWriteInputTokens' => 131,
        'cacheReadInputTokens' => 4230,
        'thoughtTokens' => null,
    ]);

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream'));
});

it('can handle tool calls', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-handle-tool-cals');

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
        ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
        ->withPrompt('What is the weather like in Detroit today?')
        ->withMaxSteps(2)
        ->withTools($tools)
        ->asStream();

    $toolCallFound = false;
    $toolResults = [];
    $events = [];
    $text = '';

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ToolCallEvent) {
            $toolCallFound = true;
            expect($event->toolCall->name)->not->toBeEmpty();
            expect($event->toolCall->arguments())->toBeArray();
        }

        if ($event instanceof ToolResultEvent) {
            $toolResults[] = $event;
        }

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCallFound)->toBeTrue('Expected to find at least one tool call in the stream');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return str_ends_with($request->url(), urlencode('us.amazon.nova-micro-v1:0').'/converse-stream')
            && isset($body['toolConfig']);
    });
});

it('can handle thinking', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-with-reasoning');

    $response = Prism::text()
        ->using('bedrock', 'us.anthropic.claude-sonnet-4-20250514-v1:0')
        ->withProviderOptions([
            'apiSchema' => BedrockSchema::Converse,
            'additionalModelRequestFields' => [
                'thinking' => [
                    'type' => 'enabled',
                    'budget_tokens' => 1024,
                ],
            ],
            'inferenceConfig' => [
                'maxTokens' => 5000,
            ],
        ])
        ->withPrompt('Who are you?')
        ->asStream();

    $events = collect();

    foreach ($response as $event) {
        $events->push($event);
    }

    expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingStart)->sole())
        ->toBeInstanceOf(ThinkingStartEvent::class);

    $thinkingDeltas = $events->where(
        fn (StreamEvent $event): bool => $event->type() === StreamEventType::ThinkingDelta
    );

    $thinkingDeltas
        ->each(function (StreamEvent $event): void {
            expect($event)->toBeInstanceOf(ThinkingEvent::class);
        });

    expect($thinkingDeltas->count())->toBeGreaterThan(2);

    expect($thinkingDeltas->first()->delta)->not->toBeEmpty();

    expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingComplete)->sole())
        ->toBeInstanceOf(ThinkingCompleteEvent::class);
});

it('carries the thinking block into the next request when a tool is called', function (): void {
    $files = [
        FixtureResponse::filePath('converse/stream-with-reasoning-then-tool-use-1.sse'),
        FixtureResponse::filePath('converse/stream-with-reasoning-then-tool-use-2.sse'),
    ];

    $capturedPayloads = [];

    Http::fake(function (Request $request) use (&$capturedPayloads, &$files): PromiseInterface {
        $capturedPayloads[] = json_decode($request->body(), true);

        $file = array_shift($files);

        return Http::response(
            file_get_contents($file),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked',
            ]
        );
    });

    $tool = Tool::as('search')
        ->for('useful for searching')
        ->withStringParameter('query', 'The search query')
        ->using(fn (string $query): string => 'contact found: Nyx');

    $events = collect();

    $response = Prism::text()
        ->using('bedrock', 'us.anthropic.claude-sonnet-4-20250514-v1:0')
        ->withProviderOptions([
            'apiSchema' => BedrockSchema::Converse,
            'additionalModelRequestFields' => [
                'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
            ],
        ])
        ->withTools([$tool])
        ->withMaxSteps(2)
        ->withPrompt('Look up the contact named Nyx')
        ->asStream();

    foreach ($response as $event) {
        $events->push($event);
    }

    expect($capturedPayloads)->toHaveCount(2);

    $secondRequestMessages = $capturedPayloads[1]['messages'];
    $assistantTurn = collect($secondRequestMessages)->firstWhere('role', 'assistant');

    expect($assistantTurn)->not->toBeNull();
    expect($assistantTurn['content'][0])->toHaveKey('reasoningContent');
    expect($assistantTurn['content'][0]['reasoningContent']['reasoningText']['text'])
        ->toBe('I should look up this contact first. Let me call the search tool.');
    expect($assistantTurn['content'][0]['reasoningContent']['reasoningText']['signature'])
        ->toBe('sig-EqUCCkgICBABGAIiQI3example1234567890');
});

describe('citations', function (): void {
    it('emits CitationEvent and includes citations in StreamEndEvent', function (): void {
        FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-with-citations');

        $response = Prism::text()
            ->using('bedrock', 'us.anthropic.claude-sonnet-4-20250514-v1:0')
            ->withMessages([
                (new UserMessage(
                    content: 'What is the answer to life?',
                    additionalContent: [
                        Document::fromLocalPath('tests/Fixtures/document.pdf', 'The Answer To Life'),
                    ]
                ))->withProviderOptions([
                    'citations' => [
                        'enabled' => true,
                    ],
                ]),
            ])
            ->asStream();

        $text = '';
        $events = [];
        $citationEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }

            if ($event instanceof CitationEvent) {
                $citationEvents[] = $event;
            }
        }

        $lastEvent = end($events);

        // Check that citation events were emitted
        expect($citationEvents)->not->toBeEmpty();
        expect($citationEvents[0])->toBeInstanceOf(CitationEvent::class);
        expect($citationEvents[0]->citation)->toBeInstanceOf(Citation::class);
        expect($citationEvents[0]->messageId)->not->toBeEmpty();

        // Check that the StreamEndEvent contains citations
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->citations)->toBeArray();
        expect($lastEvent->citations)->not->toBeEmpty();
        expect($lastEvent->citations[0])->toBeInstanceOf(MessagePartWithCitations::class);
        expect($lastEvent->citations[0]->citations[0])->toBeInstanceOf(Citation::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    });

    it('can send citation data to model', function (): void {
        FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-with-previous-citations');

        $messageWithCitation = new AssistantMessage(
            content: '',
            additionalContent: [
                'citations' => [
                    new MessagePartWithCitations(
                        outputText: 'The answer to life is 42.',
                        citations: [
                            new Citation(
                                sourceType: CitationSourceType::Document,
                                source: 0,
                                sourceText: 'The answer to the ultimate question of life, the universe, and everything is "42".',
                                sourceTitle: 'The Answer To Life Document',
                                sourcePositionType: CitationSourcePositionType::Page,
                                sourceStartIndex: 1,
                                sourceEndIndex: 2,
                            ),
                        ],
                    ),
                ],
            ],
        );

        $response = Prism::text()
            ->using('bedrock', 'us.anthropic.claude-sonnet-4-20250514-v1:0')
            ->withMessages([
                (new UserMessage(
                    content: 'What is the answer to life?',
                    additionalContent: [
                        Document::fromLocalPath('tests/Fixtures/document.pdf', 'The Answer To Life'),
                    ]
                ))->withProviderOptions([
                    'citations' => [
                        'enabled' => true,
                    ],
                ]),
                $messageWithCitation,
                new UserMessage('Can you explain that further?'),
            ])
            ->asStream();

        $text = '';
        $events = [];
        $citationEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }

            if ($event instanceof CitationEvent) {
                $citationEvents[] = $event;
            }
        }

        $lastEvent = end($events);

        // Check that citation events were emitted
        expect($citationEvents)->not->toBeEmpty();
        expect($citationEvents[0])->toBeInstanceOf(CitationEvent::class);
        expect($citationEvents[0]->citation)->toBeInstanceOf(Citation::class);
        expect($citationEvents[0]->messageId)->not->toBeEmpty();

        // Check that the StreamEndEvent contains citations
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->citations)->toBeArray();
        expect($lastEvent->citations)->not->toBeEmpty();
        expect($lastEvent->citations[0])->toBeInstanceOf(MessagePartWithCitations::class);
        expect($lastEvent->citations[0]->citations[0])->toBeInstanceOf(Citation::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    });
});

it('throws with the full event when a frame has no event type', function (): void {
    $headers = chr(13).':message-type'.chr(7).pack('n', 9).'exception'
        .chr(15).':exception-type'.chr(7).pack('n', 10).'mysteryErr';
    $payload = '{"message":"something unexpected"}';
    $prelude = pack('N', 16 + strlen($headers) + strlen($payload)).pack('N', strlen($headers));
    $message = $prelude.pack('N', crc32($prelude)).$headers.$payload;
    $frame = $message.pack('N', crc32($message));

    Http::fake(['converse-stream' => Http::response($frame, 200)])->preventStrayRequests();

    expect(fn () => iterator_to_array(
        Prism::text()
            ->using('bedrock', 'us.amazon.nova-micro-v1:0')
            ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
            ->withPrompt('Who are you?')
            ->asStream()
    ))->toThrow(PrismException::class, 'something unexpected');
});
