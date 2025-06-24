<?php

declare(strict_types=1);

namespace Prism\Bedrock\Schemas\Converse\Maps;

use Prism\Prism\Tool as PrismTool;

class ToolMap
{
    /**
     * @param  PrismTool[]  $tools
     * @return array<string, mixed>
     */
    public static function map(array $tools): array
    {
        return array_map(fn (PrismTool $tool): array => [
            'toolSpec' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => [
                    'json' => [
                        'type' => 'object',
                        'properties' => $tool->parametersAsArray(),
                        'required' => $tool->requiredParameters(),
                    ],
                ],
            ],
        ], $tools);
    }
}
