<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditCardTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'credit_card_id',
        'credit_card_invoice_id',
        'category_id',
        'description',
        'amount',
        'purchase_date',
        'installment_number',
        'installments_total',
        'group_id',
        'receipt_path',
        'import_fingerprint',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'purchase_date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'credit_card_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class, 'credit_card_invoice_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }
}
