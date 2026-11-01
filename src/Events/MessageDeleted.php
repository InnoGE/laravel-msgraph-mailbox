<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Events;

final readonly class MessageDeleted extends AbstractMessageEvent implements MessageRemoved {}
