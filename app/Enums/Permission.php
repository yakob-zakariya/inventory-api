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
    case SuppliersManage = 'suppliers.manage';
    case CustomersManage = 'customers.manage';
    case ReportsView = 'reports.view';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case CostsView = 'costs.view';

    /**
     * What the permission allows, as shown in the role editor.
     */
    public function label(): string
    {
        return match ($this) {
            self::ProductsManage => 'Create, edit and delete products and categories',
            self::StockAdjust => 'Adjust stock with a reason',
            self::PurchasesCreate => 'Record purchases, see them and pay suppliers',
            self::PurchasesVoid => 'Void purchases',
            self::SalesCreate => 'Record sales and edit their notes',
            self::SalesVoid => 'Void sales',
            self::PaymentsRecord => 'Take payments against sales (and purchases, with purchases)',
            self::PaymentsVoid => 'Reverse payments',
            self::ExpensesRecord => 'Record expenses',
            self::AccountsManage => 'Manage accounts, transfers, owner entries, reconciliation and reversals',
            self::SuppliersManage => 'Create, edit and delete suppliers',
            self::CustomersManage => 'Create, edit and delete customers',
            self::ReportsView => 'See reports, balances, costs and the money ledger',
            self::UsersManage => 'Manage users and roles',
            self::SettingsManage => 'Edit the shop name and contact details printed on documents',
            self::CostsView => 'See product costs, additional costs and estimated profit',
        };
    }

    /**
     * The section the permission is listed under in the role editor.
     */
    public function group(): string
    {
        return match ($this) {
            self::ProductsManage, self::StockAdjust => 'Products and stock',
            self::PurchasesCreate, self::PurchasesVoid, self::SuppliersManage => 'Purchases',
            self::SalesCreate, self::SalesVoid, self::CustomersManage => 'Sales',
            self::PaymentsRecord, self::PaymentsVoid, self::ExpensesRecord, self::AccountsManage => 'Money',
            self::ReportsView, self::CostsView => 'Reports',
            self::UsersManage, self::SettingsManage => 'Administration',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
