<?php

namespace App\Study\Watch;

enum AlertLevel: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Crit = 'crit';
    case Ok = 'ok';
}
