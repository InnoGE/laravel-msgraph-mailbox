<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Enums;

enum LifecycleEvent: string
{
    case Missed = 'missed';
    case ReauthorizationRequired = 'reauthorizationRequired';
    case SubscriptionRemoved = 'subscriptionRemoved';
}
