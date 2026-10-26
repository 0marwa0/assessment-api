<?php

namespace App\Enums;

enum UserRole: string
{
    case EndUser = 'end_user';
    case Manager = 'manager';
}
