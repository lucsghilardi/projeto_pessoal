<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payable extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'description',
        'amount',
        'due_date',
        'is_paid',
        'paid_at',
        'bank_account_id',
        'kind',
        'installment_number',
        'installments_total',
        'group_id',
        'receipt_path',
        'import_fingerprint',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date:Y-m-d',
        'paid_at' => 'date:Y-m-d',
        'is_paid' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
