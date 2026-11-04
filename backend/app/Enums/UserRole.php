<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Marketer = 'marketer';
    case Viewer = 'viewer';
    case Client = 'client';

    public function canManage(): bool
    {
        return in_array($this, [self::Admin, self::Marketer], true);
    }
}
