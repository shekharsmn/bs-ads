<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Marketer = 'marketer';
    case Viewer = 'viewer';

    public function canManage(): bool
    {
        return $this !== self::Viewer;
    }
}
