<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One OpenRouter call (OpenRouterClient logs them all): feature, model, tokens and cost in USD.
 */
class AiCall extends Model
{
    public const UPDATED_AT = null;

    /** What each call was for, as shown in «Costi IA». */
    public const FEATURES = [
        'expense' => 'Lettura di spese e fatture fornitore',
        'organization' => 'Lettura di clienti e fornitori',
        'product_link' => 'Preventivatore: prodotto da link',
        'product_screenshot' => 'Preventivatore: prodotto da screenshot',
        'advantages' => 'Preventivatore: vantaggi per il cliente',
        'test' => 'Prova del modello',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'cost' => 'decimal:6',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function featureLabel(string $feature): string
    {
        return self::FEATURES[$feature] ?? $feature;
    }
}
