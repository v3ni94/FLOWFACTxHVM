<?php

declare(strict_types=1);

namespace App\Flowfact\Query;

/**
 * Erzeugt das bestätigte Flowdsl-JSON (flowfact-api.md Abschnitt 5.6,
 * Nachtrag aus @flowfact/node-flowdsl 3.0.1).
 *
 * Ausschließlich die dort belegten Schlüssel werden erzeugt; die
 * Reihenfolge entspricht dem Beispiel des Builders.
 */
final class Flowdsl
{
    public const string EQUALS = 'EQUALS';

    public const string LIKE = 'LIKE';

    /** @var list<string> */
    private array $fetch = [];

    /** @var list<array<string, mixed>> */
    private array $conditions = [];

    /** @var list<array<string, string>> */
    private array $sorts = [];

    public static function create(): self
    {
        return new self;
    }

    public function hasFieldWithValue(string $field, string $value, string $operator = self::EQUALS): self
    {
        $this->conditions[] = [
            'type' => 'HASFIELDWITHVALUE',
            'field' => $field,
            'value' => $value,
            'operator' => $operator,
        ];

        return $this;
    }

    public function hasEntityIds(string ...$entityIds): self
    {
        $this->conditions[] = [
            'type' => 'ENTITYID',
            'values' => array_values($entityIds),
        ];

        return $this;
    }

    /**
     * @param  list<string>  $fields
     */
    public function fetch(array $fields): self
    {
        $this->fetch = array_values($fields);

        return $this;
    }

    public function sort(string $field, string $direction = 'ASC'): self
    {
        $this->sorts[] = ['field' => $field, 'direction' => $direction];

        return $this;
    }

    /**
     * @return array{target: string, fetch: list<string>, aggregations: list<mixed>, conditions: list<array<string, mixed>>, distinct: bool, joins: list<mixed>, sorts: list<array<string, string>>, schemaIds: list<string>}
     */
    public function toArray(): array
    {
        return [
            'target' => 'ENTITY',
            'fetch' => $this->fetch,
            'aggregations' => [],
            'conditions' => $this->conditions,
            'distinct' => false,
            'joins' => [],
            'sorts' => $this->sorts,
            'schemaIds' => [],
        ];
    }
}
