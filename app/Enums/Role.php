<?php

namespace App\Enums;

enum Role: string
{
    case Tutor = 'tutor';
    case ProgramManager = 'program_manager';
    case Admin = 'admin';
}
