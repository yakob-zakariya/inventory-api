<?php

use App\Enums\DocumentType;
use App\Services\DocumentNumberer;
use Illuminate\Support\Facades\DB;

it('hands out sequential numbers per document type', function () {
    $numberer = app(DocumentNumberer::class);

    $numbers = DB::transaction(fn () => [
        $numberer->next(DocumentType::Sale),
        $numberer->next(DocumentType::Sale),
        $numberer->next(DocumentType::Purchase),
    ]);

    expect($numbers)->toBe(['SAL-000001', 'SAL-000002', 'PUR-000001']);
});

it('gives the number back when the transaction rolls back', function () {
    $numberer = app(DocumentNumberer::class);

    try {
        DB::transaction(function () use ($numberer) {
            $numberer->next(DocumentType::Sale);

            throw new RuntimeException('Sale failed');
        });
    } catch (RuntimeException) {
    }

    expect(DB::transaction(fn () => $numberer->next(DocumentType::Sale)))->toBe('SAL-000001');
});

it('refuses to number outside a transaction', function () {
    DB::commit();

    try {
        expect(fn () => app(DocumentNumberer::class)->next(DocumentType::Sale))
            ->toThrow(LogicException::class, 'inside a database transaction');
    } finally {
        DB::beginTransaction();
    }
});
