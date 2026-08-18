<?php

namespace App\Domain\Notifications;

enum NotificationEmailPreference: string
{
    case IMMEDIATE = 'immediate';
    case DIGEST = 'digest';
    case OFF = 'off';
}
