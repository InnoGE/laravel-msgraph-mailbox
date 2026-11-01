<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Enums;

enum BodyType: string
{
    case Text = 'text';
    case Html = 'html';
}
