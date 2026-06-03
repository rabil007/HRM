<?php

namespace App\Models;

use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'slug',
        'label',
        'category',
        'meta_name',
        'meta_language',
        'header_type',
        'body_preview',
        'is_default',
        'enabled',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'enabled' => 'boolean',
            'sort_order' => 'integer',
            'category' => WhatsAppTemplateCategory::class,
            'header_type' => WhatsAppTemplateHeaderType::class,
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCategory(Builder $query, WhatsAppTemplateCategory|string $category): Builder
    {
        $value = $category instanceof WhatsAppTemplateCategory ? $category->value : $category;

        return $query->where('category', $value);
    }

    public static function resolveBySlug(string $slug): self
    {
        $template = self::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->first();

        if ($template === null) {
            throw new InvalidArgumentException("WhatsApp template [{$slug}] was not found or is disabled.");
        }

        return $template;
    }

    public static function defaultForCategory(WhatsAppTemplateCategory|string $category): self
    {
        $value = $category instanceof WhatsAppTemplateCategory ? $category : WhatsAppTemplateCategory::from($category);

        $template = self::query()
            ->enabled()
            ->forCategory($value)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->first();

        if ($template !== null) {
            return $template;
        }

        $fallback = self::query()
            ->enabled()
            ->forCategory($value)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->first();

        if ($fallback === null) {
            throw new RuntimeException("No enabled WhatsApp template exists for category [{$value->value}].");
        }

        return $fallback;
    }

    public function previewBodyFor(string $sampleName): string
    {
        $sampleName = trim($sampleName) !== '' ? trim($sampleName) : 'Employee Name';

        return str_replace('{{name}}', $sampleName, $this->body_preview);
    }

    /**
     * @return array<string, mixed>
     */
    public function toBrowseArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'label' => $this->label,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'meta_name' => $this->meta_name,
            'meta_language' => $this->meta_language,
            'header_type' => $this->header_type->value,
            'header_type_label' => $this->header_type->label(),
            'body_preview' => $this->body_preview,
            'is_default' => $this->is_default,
            'enabled' => $this->enabled,
            'sort_order' => $this->sort_order,
        ];
    }

    public function markAsDefaultForCategory(): void
    {
        self::query()
            ->where('category', $this->category)
            ->whereKeyNot($this->id)
            ->update(['is_default' => false]);

        $this->forceFill(['is_default' => true])->save();
    }
}
