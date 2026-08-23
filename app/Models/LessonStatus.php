<?php

namespace App\Models;

enum LessonStatus: string
{
    case Preparation = 'preparation';
    case Prepared = 'prepared';
    case Taught = 'taught';
}
