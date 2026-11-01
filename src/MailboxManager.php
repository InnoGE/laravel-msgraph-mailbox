<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Contracts\MessageGateway;
use InnoGE\LaravelMsGraphMailbox\Exceptions\InvalidConfigurationException;
use InnoGE\LaravelMsGraphMailbox\Exceptions\MailboxNotAllowedException;

final class MailboxManager
{
    /** @var (Closure(): iterable<array-key, mixed>)|iterable<array-key, mixed>|null */
    private Closure|iterable|null $observed = null;

    public function __construct(private readonly Container $container) {}

    /**
     * Get a client for the given observed or allowed mailbox.
     */
    public function for(string $address): MailboxClient
    {
        if (! $this->allows($address)) {
            throw new MailboxNotAllowedException($address);
        }

        return $this->forUnrestricted($address);
    }

    /**
     * Get a client for any mailbox. Authorizing the address is up to the caller.
     */
    public function forUnrestricted(string $address): MailboxClient
    {
        return new MailboxClient(ObservedMailbox::normalize($address), $this->container->make(MessageGateway::class));
    }

    /**
     * Determine if the given mailbox may be acted on.
     */
    public function allows(string $address): bool
    {
        $address = ObservedMailbox::normalize($address);

        foreach ($this->observed() as $mailbox) {
            if ($mailbox->address === $address) {
                return true;
            }
        }

        $patterns = array_map(Str::lower(...), array_filter(config()->array('msgraph-mailbox.allowed_mailboxes'), is_string(...)));

        return $patterns !== [] && Str::is($patterns, $address);
    }

    /**
     * Observe the given mailboxes instead of the configured ones.
     *
     * @param  (Closure(): iterable<array-key, mixed>)|iterable<array-key, mixed>  $mailboxes
     */
    public function observe(Closure|iterable $mailboxes): void
    {
        $this->observed = $mailboxes;
    }

    /**
     * Get the mailboxes the application observes.
     *
     * @return list<ObservedMailbox>
     */
    public function observed(): array
    {
        $mailboxes = [];
        $observed = $this->observed ?? config()->array('msgraph-mailbox.mailboxes');

        foreach ($observed instanceof Closure ? $observed() : $observed as $key => $value) {
            $mailboxes[] = match (true) {
                is_int($key) && is_string($value) => new ObservedMailbox($value),
                is_string($key) && is_array($value) => new ObservedMailbox(
                    $key,
                    (array) ($value['folders'] ?? ObservedMailbox::DEFAULT_FOLDERS),
                    (array) ($value['events'] ?? ObservedMailbox::DEFAULT_EVENTS),
                ),
                default => throw new InvalidConfigurationException('mailboxes', 'expected a list of addresses or a map of address => options'),
            };
        }

        return $mailboxes;
    }
}
