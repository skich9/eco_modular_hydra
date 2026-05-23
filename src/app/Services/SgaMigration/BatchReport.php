<?php

namespace App\Services\SgaMigration;

class BatchReport
{
    private array $tables = [];

    public function record(string $table, string $conn, string $status): void
    {
        $key = "{$conn}.{$table}";
        if (!isset($this->tables[$key])) {
            $this->tables[$key] = ['table' => $table, 'conn' => $conn, 'inserted' => 0, 'skipped' => 0, 'errors' => 0];
        }
        $this->tables[$key][$status === 'inserted' ? 'inserted' : ($status === 'skipped' ? 'skipped' : 'errors')]++;
    }

    public function rows(): array
    {
        return array_values($this->tables);
    }

    public function totalErrors(): int
    {
        return array_sum(array_column($this->tables, 'errors'));
    }

    public function totalInserted(): int
    {
        return array_sum(array_column($this->tables, 'inserted'));
    }
}
