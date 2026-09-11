<?php

namespace App\Support\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final readonly class MasterDataUsageSource
{
    public function __construct(
        public string $label,
        public string $table,
        public string $column,
        public bool $softDeletes = false,
        public ?string $companyColumn = null,
        public ?string $relatedTable = null,
        public ?string $relatedLocalKey = null,
        public string $relatedForeignKey = 'id',
        public bool $relatedSoftDeletes = false,
    ) {}

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function model(
        string $label,
        string $modelClass,
        string $column,
        ?string $companyColumn = null,
    ): self {
        $model = new $modelClass;

        return new self(
            label: $label,
            table: $model->getTable(),
            column: $column,
            softDeletes: self::usesSoftDeletes($modelClass),
            companyColumn: $companyColumn,
        );
    }

    /**
     * @param  class-string<Model>  $relatedClass
     */
    public static function pivot(
        string $label,
        string $pivotTable,
        string $column,
        string $relatedClass,
        string $relatedLocalKey,
    ): self {
        $related = new $relatedClass;

        return new self(
            label: $label,
            table: $pivotTable,
            column: $column,
            relatedTable: $related->getTable(),
            relatedLocalKey: $relatedLocalKey,
            relatedSoftDeletes: self::usesSoftDeletes($relatedClass),
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private static function usesSoftDeletes(string $modelClass): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
    }
}
