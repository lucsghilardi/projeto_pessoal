<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consorcio extends Model
{
    protected $table = 'consorcios';

    protected $fillable = [
        'user_id',
        'nome',
        'administradora',
        'tipo',
        'grupo',
        'cota',
        'valor_credito',
        'valor_mensalidade',
        'reducao_pct',
        'prazo_meses',
        'parcelas_pagas',
        'valor_pago_inicial',
        'dia_vencimento',
        'data_contemplacao',
        'status',
        'proposta_path',
        'observacoes',
    ];

    protected $casts = [
        'valor_credito' => 'decimal:2',
        'valor_mensalidade' => 'decimal:2',
        'reducao_pct' => 'decimal:2',
        'valor_pago_inicial' => 'decimal:2',
        'prazo_meses' => 'integer',
        'parcelas_pagas' => 'integer',
        'dia_vencimento' => 'integer',
        'data_contemplacao' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Parcelas do carnê — armazenadas como contas a pagar vinculadas ao consórcio. */
    public function parcelas(): HasMany
    {
        return $this->hasMany(Payable::class)->orderBy('due_date')->orderBy('installment_number');
    }
}
