<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SchemaRepair
{
    public static function identifier(ColumnDefinition $column): ColumnDefinition
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return $column->collation('binary');
        }

        return $column->charset('ascii')->collation('ascii_bin');
    }

    public static function columns(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new RuntimeException("Incomplete schema: {$table}.{$column} is missing; restore or repair before continuing.");
            }
        }
    }

    public static function index(string $table, array $columns, string $name, bool $unique = false): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name) {
                if ($index['columns'] !== $columns || (bool) $index['unique'] !== $unique) {
                    throw new RuntimeException("Unexpected index definition: {$name}.");
                }

                return;
            }
        }
        Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
    }

    public static function foreign(string $table, string $column, string $target, string $name, string $delete = 'cascade'): void
    {
        if (! Schema::hasColumn($table, $column)) {
            throw new RuntimeException("Incomplete schema: {$table}.{$column} is missing.");
        }
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                if ($key['foreign_table'] !== $target || $key['foreign_columns'] !== ['id'] || strtolower($key['on_delete'] ?? $delete) !== $delete) {
                    throw new RuntimeException("Unexpected foreign key on {$table}.{$column}.");
                }

                return;
            }
        }
        if (DB::table($table)->whereNotNull($column)->whereNotExists(function ($q) use ($table, $column, $target) {
            $q->selectRaw('1')->from($target)->whereColumn("{$target}.id", "{$table}.{$column}");
        })->exists()) {
            throw new RuntimeException("Cannot repair {$name}: orphaned records require review.");
        }
        Schema::table($table, fn (Blueprint $t) => $t->foreign($column, $name)->references('id')->on($target)->onDelete($delete));
    }
}
