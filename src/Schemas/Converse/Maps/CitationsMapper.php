<?php

namespace Prism\Bedrock\Schemas\Converse\Maps;

use Prism\Prism\Enums\Citations\CitationSourcePositionType;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

class CitationsMapper
{
    /**
     * @param  array<string, mixed>  $contentBlock
     */
    public static function mapFromConverse(array $contentBlock): ?MessagePartWithCitations
    {
        if (! isset($contentBlock['citationsContent']['citations'])) {
            return null;
        }

        $citations = array_map(
            fn (array $citationData): Citation => self::mapCitationFromConverse($citationData),
            $contentBlock['citationsContent']['citations']
        );

        return new MessagePartWithCitations(
            outputText: implode('', array_map(fn (array $text) => $text['text'] ?? '', $contentBlock['citationsContent']['content'] ?? [])),
            citations: $citations,
        );
    }

    /**
     * @param  array<string, mixed>  $citationData
     */
    public static function mapCitationFromConverse(array $citationData): Citation
    {
        $location = $citationData['location'] ?? [];

        $indices = $location['documentChar'] ?? $location['documentChunk'] ?? $location['documentPage'] ?? null;

        return new Citation(
            sourceType: CitationSourceType::Document,
            source: $indices['documentIndex'] ?? 0,
            sourceText: self::mapSourceText($citationData['sourceContent'] ?? []),
            sourceTitle: $citationData['title'] ?? '',
            sourcePositionType: self::mapSourcePositionType($location),
            sourceStartIndex: $indices['start'] ?? null,
            sourceEndIndex: $indices['end'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapToConverse(MessagePartWithCitations $part): array
    {
        $citations = array_map(
            fn (Citation $citation): array => self::mapCitationToConverse($citation),
            $part->citations
        );

        return [
            'citationsContent' => [
                'citations' => array_filter($citations),
                'content' => [
                    ['text' => $part->outputText],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $citationData
     */
    protected static function mapSourceText(array $citationData): ?string
    {
        return implode("\n", array_map(
            fn (array $sourceContent): string => $sourceContent['text'] ?? '',
            $citationData
        ));
    }

    /**
     * @param  array<string, mixed>  $location
     */
    protected static function mapSourcePositionType(array $location): ?CitationSourcePositionType
    {
        return match (array_keys($location)[0] ?? null) {
            'documentChar' => CitationSourcePositionType::Character,
            'documentChunk' => CitationSourcePositionType::Chunk,
            'documentPage' => CitationSourcePositionType::Page,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapCitationToConverse(Citation $citation): array
    {
        $locationKey = match ($citation->sourcePositionType) {
            CitationSourcePositionType::Character => 'documentChar',
            CitationSourcePositionType::Chunk => 'documentChunk',
            CitationSourcePositionType::Page => 'documentPage',
            default => null,
        };

        $location = $locationKey ? [
            $locationKey => [
                'documentIndex' => $citation->source,
                'start' => $citation->sourceStartIndex,
                'end' => $citation->sourceEndIndex,
            ],
        ] : [];

        return array_filter([
            'location' => $location,
            'sourceContent' => $citation->sourceText ? [
                ['text' => $citation->sourceText],
            ] : null,
            'title' => $citation->sourceTitle ?: null,
        ]);
    }
}
