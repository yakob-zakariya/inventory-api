<?php

namespace App\Enums;

/**
 * The built-in roles. Only seeders, setup commands and the owner-role lock use these;
 * authorization checks always use {@see Permission}. Shops can add their own roles.
 */
enum Role: string
{
    case Owner = 'owner';
    case Staff = 'staff';

    /**
     * The permissions the seeder gives this role when it creates it.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Staff => [
                Permission::CustomersManage,
                Permission::SalesCreate,
                Permission::PaymentsRecord,
            ],
        };
    }
}
