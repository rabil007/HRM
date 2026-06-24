<?php

namespace App\Models;

use App\Enums\EmailTemplateCategory;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use RuntimeException;

class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'slug',
        'label',
        'category',
        'to_preset',
        'cc_preset',
        'dispatch_at',
        'subject',
        'body_html',
        'include_company_footer',
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
            'include_company_footer' => 'boolean',
            'sort_order' => 'integer',
            'category' => EmailTemplateCategory::class,
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
    public function scopeForCategory(Builder $query, EmailTemplateCategory|string $category): Builder
    {
        $value = $category instanceof EmailTemplateCategory ? $category->value : $category;

        return $query->where('category', $value);
    }

    public static function resolveBySlug(string $slug): self
    {
        $template = self::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->first();

        if ($template === null) {
            throw new InvalidArgumentException("Email template [{$slug}] was not found or is disabled.");
        }

        return $template;
    }

    public static function defaultForCategory(EmailTemplateCategory|string $category): self
    {
        $value = $category instanceof EmailTemplateCategory ? $category : EmailTemplateCategory::from($category);

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
            throw new RuntimeException("No enabled email template exists for category [{$value->value}].");
        }

        return $fallback;
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
            'to_preset' => $this->to_preset,
            'cc_preset' => $this->cc_preset,
            'dispatch_at' => $this->dispatch_at,
            'subject' => $this->subject,
            'body_html' => $this->body_html,
            'include_company_footer' => $this->include_company_footer,
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
