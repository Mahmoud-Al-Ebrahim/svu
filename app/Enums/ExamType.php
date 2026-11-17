<?php

namespace App\Enums;

enum ExamType: string
{
    case Midterm = 'midterm';
    case Final = 'final';
    case Practical = 'practical';
    case Resit = 'resit';
}
