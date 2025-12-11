<?php

declare(strict_types=1);

namespace Tests\Schemas\Anthropic\Concerns;

use Prism\Bedrock\Schemas\Anthropic\Concerns\ExtractsToolCalls;

it('extracts tool calls with array input', function (): void {
    $extractor = new class
    {
        use ExtractsToolCalls;

        public function extract(array $data): array
        {
            return $this->extractToolCalls($data);
        }
    };

    $data = [
        'content' => [
            [
                'type' => 'tool_use',
                'id' => 'tool_123',
                'name' => 'search',
                'input' => [
                    'query' => 'Laravel docs',
                ],
            ],
        ],
    ];

    $result = $extractor->extract($data);

    expect($result)->toHaveCount(1);
    expect($result[0]->id)->toBe('tool_123');
    expect($result[0]->name)->toBe('search');
    expect($result[0]->arguments())->toBe(['query' => 'Laravel docs']);
});

it('extracts tool calls with string JSON input', function (): void {
    $extractor = new class
    {
        use ExtractsToolCalls;

        public function extract(array $data): array
        {
            return $this->extractToolCalls($data);
        }
    };

    $data = [
        'content' => [
            [
                'type' => 'tool_use',
                'id' => 'tool_456',
                'name' => 'weather',
                'input' => '{"city": "Detroit"}',
            ],
        ],
    ];

    $result = $extractor->extract($data);

    expect($result)->toHaveCount(1);
    expect($result[0]->id)->toBe('tool_456');
    expect($result[0]->name)->toBe('weather');
    expect($result[0]->arguments())->toBe(['city' => 'Detroit']);
});

it('extracts tool calls with invalid JSON string input defaults to empty array', function (): void {
    $extractor = new class
    {
        use ExtractsToolCalls;

        public function extract(array $data): array
        {
            return $this->extractToolCalls($data);
        }
    };

    $data = [
        'content' => [
            [
                'type' => 'tool_use',
                'id' => 'tool_789',
                'name' => 'get_time',
                'input' => 'invalid json',
            ],
        ],
    ];

    $result = $extractor->extract($data);

    expect($result)->toHaveCount(1);
    expect($result[0]->id)->toBe('tool_789');
    expect($result[0]->name)->toBe('get_time');
    expect($result[0]->arguments())->toBe([]);
});

it('extracts tool calls with null input defaults to empty array', function (): void {
    $extractor = new class
    {
        use ExtractsToolCalls;

        public function extract(array $data): array
        {
            return $this->extractToolCalls($data);
        }
    };

    $data = [
        'content' => [
            [
                'type' => 'tool_use',
                'id' => 'tool_abc',
                'name' => 'parameterless_tool',
                'input' => null,
            ],
        ],
    ];

    $result = $extractor->extract($data);

    expect($result)->toHaveCount(1);
    expect($result[0]->id)->toBe('tool_abc');
    expect($result[0]->name)->toBe('parameterless_tool');
    expect($result[0]->arguments())->toBe([]);
});

it('extracts tool calls with empty array input', function (): void {
    $extractor = new class
    {
        use ExtractsToolCalls;

        public function extract(array $data): array
        {
            return $this->extractToolCalls($data);
        }
    };

    $data = [
        'content' => [
            [
                'type' => 'tool_use',
                'id' => 'tool_def',
                'name' => 'no_params',
                'input' => [],
            ],
        ],
    ];

    $result = $extractor->extract($data);

    expect($result)->toHaveCount(1);
    expect($result[0]->id)->toBe('tool_def');
    expect($result[0]->name)->toBe('no_params');
    expect($result[0]->arguments())->toBe([]);
});
