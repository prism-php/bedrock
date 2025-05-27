<?php

namespace Prism\Bedrock\Enums;

use Illuminate\Support\Str;
use Prism\Bedrock\Contracts\BedrockEmbeddingsHandler;
use Prism\Bedrock\Contracts\BedrockStructuredHandler;
use Prism\Bedrock\Contracts\BedrockTextHandler;
use Prism\Bedrock\Schemas\Anthropic\AnthropicStructuredHandler;
use Prism\Bedrock\Schemas\Anthropic\AnthropicTextHandler;
use Prism\Bedrock\Schemas\Cohere\CohereEmbeddingsHandler;
use Prism\Bedrock\Schemas\Converse\ConverseStructuredHandler;
use Prism\Bedrock\Schemas\Converse\ConverseTextHandler;

enum BedrockSchema: string
{
    case Converse = 'converse';
    case Anthropic = 'anthropic';
    case Cohere = 'cohere';

    /**
     * @return null|class-string<BedrockTextHandler>
     */
    public function textHandler(): ?string
    {
        return match ($this) {
            self::Anthropic => AnthropicTextHandler::class,
            self::Converse => ConverseTextHandler::class,
            default => null
        };
    }

    /**
     * @return null|class-string<BedrockStructuredHandler>
     */
    public function structuredHandler(): ?string
    {
        return match ($this) {
            self::Anthropic => AnthropicStructuredHandler::class,
            self::Converse => ConverseStructuredHandler::class,
            default => null
        };
    }

    /**
     * @return null|class-string<BedrockEmbeddingsHandler>
     */
    public function embeddingsHandler(): ?string
    {
        return match ($this) {
            self::Cohere => CohereEmbeddingsHandler::class,
            default => null
        };
    }

    public function defaultApiVersion(): ?string
    {
        return match ($this) {
            self::Anthropic => 'bedrock-2023-05-31',
            default => null
        };
    }

    public static function fromModelString(string $string): self
    {
        if (Str::contains($string, 'anthropic.')) {
            return self::Anthropic;
        }

        if (Str::contains($string, 'cohere.')) {
            return self::Cohere;
        }

        return self::Converse;
    }
}
