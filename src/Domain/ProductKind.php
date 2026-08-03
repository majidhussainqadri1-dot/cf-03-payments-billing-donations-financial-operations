<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum ProductKind: string
{
    case EDUCATION_MEMBERSHIP = 'education_membership';
    case COURSE_OR_PROGRAM = 'course_or_program';
    case AI_ADD_ON = 'ai_add_on';
    case AI_USAGE = 'ai_usage';
    case DONATION = 'donation';
    case OTHER_APPROVED = 'other_approved';
}
