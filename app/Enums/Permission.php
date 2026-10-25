<?php

namespace App\Enums;

enum Permission: string
{
    case ProductsManage = 'products.manage';
    case StockAdjust = 'stock.adjust';
    case PurchasesCreate = 'purchases.create';
    case PurchasesVoid = 'purchases.void';
    case SalesCreate = 'sales.create';
    case SalesVoid = 'sales.void';
    case PaymentsRecord = 'payments.record';
    case PaymentsVoid = 'payments.void';
    case ExpensesRecord = 'expenses.record';
    case AccountsManage = 'accounts.manage';
    case ReportsView = 'reports.view';
    case UsersManage = 'users.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
