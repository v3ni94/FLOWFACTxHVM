<?php

declare(strict_types=1);

namespace Tests\Unit\Flowfact;

use App\Flowfact\Query\Flowdsl;
use PHPUnit\Framework\TestCase;

final class FlowdslTest extends TestCase
{
    public function test_json_entspricht_dem_bestaetigten_format(): void
    {
        $dsl = Flowdsl::create()->hasFieldWithValue('identifier', 'MF-2026-0001')->toArray();

        self::assertSame([
            'target' => 'ENTITY',
            'fetch' => [],
            'aggregations' => [],
            'conditions' => [
                ['type' => 'HASFIELDWITHVALUE', 'field' => 'identifier', 'value' => 'MF-2026-0001', 'operator' => 'EQUALS'],
            ],
            'distinct' => false,
            'joins' => [],
            'sorts' => [],
            'schemaIds' => [],
        ], $dsl);

        self::assertSame(
            '{"target":"ENTITY","fetch":[],"aggregations":[],"conditions":[{"type":"HASFIELDWITHVALUE","field":"identifier","value":"MF-2026-0001","operator":"EQUALS"}],"distinct":false,"joins":[],"sorts":[],"schemaIds":[]}',
            json_encode($dsl, JSON_THROW_ON_ERROR),
        );
    }

    public function test_entity_ids_fetch_und_operator(): void
    {
        $dsl = Flowdsl::create()
            ->hasEntityIds('a', 'b')
            ->hasFieldWithValue('headline', 'Test', Flowdsl::LIKE)
            ->fetch(['headline', 'identifier'])
            ->sort('headline', 'DESC')
            ->toArray();

        self::assertSame(['type' => 'ENTITYID', 'values' => ['a', 'b']], $dsl['conditions'][0]);
        self::assertSame('LIKE', $dsl['conditions'][1]['operator']);
        self::assertSame(['headline', 'identifier'], $dsl['fetch']);
        self::assertSame([['field' => 'headline', 'direction' => 'DESC']], $dsl['sorts']);
    }
}
