<?php
namespace Translink\Utils;

class Paginator
{
    private int $page;
    private int $perPage;
    private int $offset;
    private string $sort;
    private string $order;
    private array $allowedSorts;
    private ?string $cursor;
    private ?string $cursorColumn;

    public function __construct(array $query = [], array $allowedSorts = [])
    {
        $this->page = max(1, (int)($query['page'] ?? 1));
        $this->perPage = min(100, max(1, (int)($query['per_page'] ?? 20)));
        $this->offset = ($this->page - 1) * $this->perPage;
        $this->sort = $query['sort'] ?? 'created_at';
        $this->order = strtoupper($query['order'] ?? 'DESC');
        $this->allowedSorts = $allowedSorts;
        $this->cursor = $query['cursor'] ?? null;
        $this->cursorColumn = $query['cursor_column'] ?? null;

        if (!in_array($this->sort, $this->allowedSorts) && !empty($allowedSorts)) {
            $this->sort = $allowedSorts[0];
        }

        if (!in_array($this->order, ['ASC', 'DESC'])) {
            $this->order = 'DESC';
        }
    }

    public function page(): int { return $this->page; }
    public function perPage(): int { return $this->perPage; }
    public function offset(): int { return $this->offset; }
    public function sort(): string { return $this->sort; }
    public function order(): string { return $this->order; }
    public function cursor(): ?string { return $this->cursor; }
    public function cursorColumn(): ?string { return $this->cursorColumn; }

    public function hasCursor(): bool
    {
        return $this->cursor !== null;
    }

    public function apply(string $baseSql): string
    {
        $clauses = ["ORDER BY {$this->sort} {$this->order}"];

        if ($this->hasCursor() && $this->cursorColumn) {
            $op = $this->order === 'DESC' ? '<' : '>';
            $clauses[] = "AND {$this->cursorColumn} {$op} ?";
        }

        $clauses[] = "LIMIT {$this->perPage}";

        if (!$this->hasCursor()) {
            $clauses[] = "OFFSET {$this->offset}";
        }

        return $baseSql . ' ' . implode(' ', $clauses);
    }

    public function applyCursor(string $baseSql, string $tableAlias = 'f'): string
    {
        if (!$this->hasCursor() || !$this->cursorColumn) {
            return $this->apply($baseSql);
        }

        $op = $this->order === 'DESC' ? '<' : '>';
        $col = "{$tableAlias}.{$this->cursorColumn}";

        $whereInsert = str_contains(strtoupper($baseSql), 'WHERE')
            ? "AND {$col} {$op} ?"
            : "WHERE {$col} {$op} ?";

        $insertPos = $this->findWhereInsertPos($baseSql);
        if ($insertPos !== null) {
            $sql = substr($baseSql, 0, $insertPos)
                . $whereInsert . ' '
                . substr($baseSql, $insertPos);
        } else {
            $sql = $baseSql . ' ' . $whereInsert;
        }

        return $sql . " ORDER BY {$this->sort} {$this->order} LIMIT {$this->perPage}";
    }

    private function findWhereInsertPos(string $sql): ?int
    {
        $upper = strtoupper($sql);
        $orderPos = strpos($upper, 'ORDER BY');
        if ($orderPos !== false) {
            return $orderPos;
        }
        $limitPos = strpos($upper, 'LIMIT');
        if ($limitPos !== false) {
            return $limitPos;
        }
        return null;
    }

    public function getCursorParams(): array
    {
        if (!$this->hasCursor() || !$this->cursorColumn) {
            return [];
        }
        return [$this->cursor];
    }

    public function toArray(int $total, array $items): array
    {
        $pagination = [
            'total' => $total,
            'per_page' => $this->perPage,
            'current_page' => $this->page,
            'last_page' => max(1, (int)ceil($total / $this->perPage)),
            'has_more' => $this->page * $this->perPage < $total,
        ];

        if ($this->hasCursor() && !empty($items)) {
            $lastItem = end($items);
            $cursorCol = $this->cursorColumn ?? $this->sort;
            $pagination['next_cursor'] = $lastItem[$cursorCol] ?? null;
            $pagination['cursor_column'] = $cursorCol;
        }

        return [
            'items' => $items,
            'pagination' => $pagination,
        ];
    }
}
