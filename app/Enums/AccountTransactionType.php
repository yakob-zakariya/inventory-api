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
}
