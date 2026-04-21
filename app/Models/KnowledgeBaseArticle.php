<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class KnowledgeBaseArticle extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'category',
        'tags',
        'views',
        'is_published',
        'is_featured',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'views' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($article) {
            if (empty($article->slug)) {
                $article->slug = Str::slug($article->title);
            }
        });

        static::updating(function ($article) {
            if ($article->isDirty('title') && empty($article->slug)) {
                $article->slug = Str::slug($article->title);
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
              ->orWhere('content', 'like', "%{$term}%")
              ->orWhere('excerpt', 'like', "%{$term}%");
        });
    }

    public function incrementViews(): void
    {
        $this->increment('views');
    }

    public function getReadingTime(): int
    {
        $wordCount = str_word_count(strip_tags($this->content));
        return max(1, ceil($wordCount / 200)); // Assuming 200 words per minute
    }

    public function getExcerptAttribute(): string
    {
        if ($this->attributes['excerpt'] ?? null) {
            return $this->attributes['excerpt'];
        }

        $content = strip_tags($this->content);
        return Str::limit($content, 150);
    }

    public static function getCategories(): array
    {
        return [
            'getting-started' => 'Getting Started',
            'operators' => 'Operator Management',
            'sites' => 'Site Management',
            'payments' => 'Payment Processing',
            'sessions' => 'Session Management',
            'troubleshooting' => 'Troubleshooting',
            'api' => 'API Documentation',
            'security' => 'Security',
            'maintenance' => 'Maintenance',
        ];
    }
}
