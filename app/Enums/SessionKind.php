<?php

namespace App\Enums;

enum SessionKind: string
{
    case Live = 'live'; // synchronous
    case Recorded = 'recorded'; // asynchronous
}
