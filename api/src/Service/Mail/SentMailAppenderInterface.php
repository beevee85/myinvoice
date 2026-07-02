<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

interface SentMailAppenderInterface
{
    /**
     * @param array<string,mixed>|null $profile
     * @return array{status:'skipped'|'saved'|'failed',folder:?string,error:?string}
     */
    public function appendIfEnabled(?array $profile, string $rawMessage): array;
}
