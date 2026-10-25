<?php

namespace App\Enums;

/**
 * The fixed roles shipped with v1. Only seeders and setup commands use these;
 * authorization checks always use {@see Permission}.
 */
enum Role: string
{
    case Owner = 'owner';
    case Staff = 'staff';

    /**
     * The permissions granted to this role by the seeder.
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
