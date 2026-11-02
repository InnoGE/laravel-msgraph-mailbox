<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('source files declare strict types')
    ->expect('InnoGE\LaravelMsGraphMailbox')
    ->toUseStrictTypes();
