<?php

namespace App\Enums;

/**
 * Amounts are signed: positive is money in, negative is money out.
 */
enum AccountTransactionType: string
{
    case SalePayment = 'sale_payment';
    case PurchasePayment = 'purchase_payment';
    case Expense = 'expense';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case OwnerDeposit = 'owner_deposit';
    case OwnerWithdrawal = 'owner_withdrawal';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    /**
     * The sign an amount of this type must have: 1 money in, -1 money out, null either.
     */
    public function sign(): ?int
    {
        return match ($this) {
            self::SalePayment, self::TransferIn, self::OwnerDeposit => 1,
            self::PurchasePayment, self::Expense, self::TransferOut, self::OwnerWithdrawal => -1,
            self::Adjustment, self::Reversal => null,
        };
    }

    /**
     * Whether a reason (note) is required for this type.
     */
    public function requiresNote(): bool
    {
        return in_array($this, [self::Expense, self::Adjustment, self::Reversal], true);
    }
}
