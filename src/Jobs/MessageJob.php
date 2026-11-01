<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use InnoGE\LaravelMsGraphMailbox\MailboxManager;
use InnoGE\LaravelMsGraphMailbox\Message;
use InnoGE\LaravelMsGraphMailbox\ObservedMailbox;

/** @internal */
abstract class MessageJob extends GraphJob implements ShouldBeUnique
{
    public int $uniqueFor = 300;

    public readonly string $mailbox;

    /**
     * @param  list<mixed>  $uniqueBy
     */
    public function __construct(
        string $mailbox,
        public readonly string $messageId,
        protected readonly array $uniqueBy = [],
    ) {
        parent::__construct();

        $this->mailbox = ObservedMailbox::normalize($mailbox);
    }

    abstract protected function perform(Message $message): void;

    public function handle(MailboxManager $mailboxes): void
    {
        $this->perform($mailboxes->for($this->mailbox)->message($this->messageId));
    }

    public function uniqueId(): string
    {
        return hash('xxh128', serialize([$this->mailbox, $this->messageId, $this->uniqueBy]));
    }

    protected function mailbox(): string
    {
        return $this->mailbox;
    }
}
