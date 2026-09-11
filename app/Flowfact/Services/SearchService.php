<?php

declare(strict_types=1);

namespace App\Flowfact\Services;

use App\Flowfact\Query\Flowdsl;

/**
 * search-service (flowfact-api.md Abschnitt 5.6 und 7).
 */
final class SearchService extends AbstractService
{
    public const string SERVICE = 'search-service';

    /**
     * Sucht Entitäten, deren Feld exakt (EQUALS) den Wert trägt.
     *
     * @return array{entries: list<array<string, mixed>>, totalCount: int}
     */
    public function findByField(string $index, string $field, string $value, int $size = 2, string $operator = Flowdsl::EQUALS): array
    {
        $dsl = Flowdsl::create()->hasFieldWithValue($field, $value, $operator)->toArray();

        $antwort = $this->client->post(
            self::SERVICE,
            '/schemas/{index}',
            ['index' => $index],
            $dsl,
            ['page' => 1, 'size' => $size, 'withCount' => 'true'],
        );

        $eintraege = is_array($antwort) && isset($antwort['entries']) && is_array($antwort['entries'])
            ? array_values(array_filter($antwort['entries'], 'is_array'))
            : [];

        $totalCount = is_array($antwort) && isset($antwort['totalCount']) && is_numeric($antwort['totalCount'])
            ? (int) $antwort['totalCount']
            : count($eintraege);

        return ['entries' => $eintraege, 'totalCount' => $totalCount];
    }
}
