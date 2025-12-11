<?php

namespace Prism\Bedrock\Schemas\Anthropic\Concerns;

use Prism\Prism\ValueObjects\ToolCall;

trait ExtractsToolCalls
{
    /**
     * @param  array<string, mixed>  $data
     * @return ToolCall[]
     */
    protected function extractToolCalls(array $data): array
    {
        $toolCalls = array_map(function ($content): ?ToolCall {
            if (data_get($content, 'type') === 'tool_use') {
                $input = data_get($content, 'input');

                return new ToolCall(
                    id: data_get($content, 'id'),
                    name: data_get($content, 'name'),
                    arguments: is_string($input) ? (json_decode($input, true) ?? []) : ($input ?? [])
                );
            }

            return null;
        }, data_get($data, 'content', []));

        return array_values(array_filter($toolCalls, fn (?ToolCall $toolCall): bool => $toolCall instanceof ToolCall));
    }
}
