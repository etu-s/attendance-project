<?php

declare(strict_types=1);

namespace App\Modules\Organization\Enums;

enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Temporary = 'temporary';
    case Intern = 'intern';
}
