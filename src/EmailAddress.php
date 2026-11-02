<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

final readonly class EmailAddress
{
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {}

    public static function fromGraph(mixed $recipient): ?self
    {
        $address = data_get($recipient, 'emailAddress.address');
        $name = data_get($recipient, 'emailAddress.name');

        return is_string($address) ? new self($address, is_string($name) ? $name : null) : null;
    }
}
