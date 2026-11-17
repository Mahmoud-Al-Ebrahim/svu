<?php

namespace App\Enums;

enum ExamStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Done = 'done';
}
