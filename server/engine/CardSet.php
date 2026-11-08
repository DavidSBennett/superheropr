<?php
// Card data access. Rows are plain assoc arrays matching the CSV schemas.
// Card data is never hard-coded: a CardSet is built from the importer's DB
// rows or, for the CLI, straight from the CSV files via Validate::loadCsvDir.

final class CardSet
{
    /** @var array<string,array> */ public array $crises = [];
    /** @var array<string,array> */ public array $locations = [];
    /** @var array<string,array> */ public array $mandates = [];
    /** @var array<string,array> */ public array $fallen = [];
    /** @var array<string,array> */ public array $spin = [];

    public const KINDS = ['crisis', 'location', 'mandate', 'fallen', 'spin'];

    /** @param array<int,array{kind:string,card_id:string,data:array}> $rows */
    public static function fromRows(array $rows): self
    {
        $cs = new self();
        $map = [
            'crisis' => 'crises', 'location' => 'locations',
            'mandate' => 'mandates', 'fallen' => 'fallen', 'spin' => 'spin',
        ];
        foreach ($rows as $r) {
            $prop = $map[$r['kind']] ?? null;
            if ($prop === null) {
                throw new RuntimeException("unknown card kind {$r['kind']}");
            }
            $cs->{$prop}[$r['card_id']] = $r['data'];
        }
        return $cs;
    }

    public function crisis(string $id): array
    {
        return $this->crises[$id] ?? throw new RuntimeException("no crisis $id");
    }

    public function location(string $id): array
    {
        return $this->locations[$id] ?? throw new RuntimeException("no location $id");
    }

    /** Crisis cost as [CUR => n], zero entries dropped. */
    public function crisisCost(string $id): array
    {
        $c = $this->crisis($id);
        $cost = [];
        foreach (Engine::CURRENCIES as $cur) {
            $n = (int)$c['cost_' . strtolower($cur)];
            if ($n > 0) { $cost[$cur] = $n; }
        }
        return $cost;
    }
}
