<?php

namespace App\Enums;

enum DocumentTemplateLayoutPreflightStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';
}
