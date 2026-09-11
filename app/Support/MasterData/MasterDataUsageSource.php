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
        public bool $tableUsesSoftDeletes = false,
        public bool $includeSoftDeletedReferences = false,
        public ?string $companyColumn = null,
        public ?string $relatedTable = null,
        public ?string $relatedLocalKey = null,
        public string $relatedForeignKey = 'id',
        public bool $relatedUsesSoftDeletes = false,
        public bool $includeSoftDeletedRelatedReferences = false,
        public ?string $relatedCompanyColumn = null,
        public ?string $jsonPath = null,
        public ?string $whereColumn = null,
        public mixed $whereValue = null,
    ) {}

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function model(
        string $label,
        string $modelClass,
        string $column,
        ?string $companyColumn = null,
        bool $includeSoftDeletedReferences = false,
    ): self {
        $model = new $modelClass;

        return new self(
            label: $label,
            table: $model->getTable(),
            column: $column,
            tableUsesSoftDeletes: self::modelUsesSoftDeletes($modelClass),
            includeSoftDeletedReferences: $includeSoftDeletedReferences,
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
        bool $includeSoftDeletedReferences = false,
        bool $includeSoftDeletedRelatedReferences = false,
        ?string $relatedCompanyColumn = 'company_id',
    ): self {
        $related = new $relatedClass;

        return new self(
            label: $label,
            table: $pivotTable,
            column: $column,
            includeSoftDeletedReferences: $includeSoftDeletedReferences,
            relatedTable: $related->getTable(),
            relatedLocalKey: $relatedLocalKey,
            relatedUsesSoftDeletes: self::modelUsesSoftDeletes($relatedClass),
            includeSoftDeletedRelatedReferences: $includeSoftDeletedRelatedReferences,
            relatedCompanyColumn: $relatedCompanyColumn,
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function json(
        string $label,
        string $modelClass,
        string $jsonColumn,
        string $jsonPath,
        ?string $companyColumn = null,
        bool $includeSoftDeletedReferences = false,
        ?string $whereColumn = null,
        mixed $whereValue = null,
    ): self {
        $model = new $modelClass;

        return new self(
            label: $label,
            table: $model->getTable(),
            column: $jsonColumn,
            tableUsesSoftDeletes: self::modelUsesSoftDeletes($modelClass),
            includeSoftDeletedReferences: $includeSoftDeletedReferences,
            companyColumn: $companyColumn,
            jsonPath: $jsonPath,
            whereColumn: $whereColumn,
            whereValue: $whereValue,
        );
    }

    public static function table(
        string $label,
        string $table,
        string $column,
        ?string $companyColumn = null,
        bool $includeSoftDeletedReferences = false,
        bool $tableUsesSoftDeletes = false,
    ): self {
        return new self(
            label: $label,
            table: $table,
            column: $column,
            tableUsesSoftDeletes: $tableUsesSoftDeletes,
            includeSoftDeletedReferences: $includeSoftDeletedReferences,
            companyColumn: $companyColumn,
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private static function modelUsesSoftDeletes(string $modelClass): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
    }
}
