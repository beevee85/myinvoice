<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * FORK (beevee85) — PSR-3 logger, který si zprávy nechává v paměti.
 *
 * Stínová validace (V76) nic nevrací ani nemění v databázi — jediná její stopa je
 * záznam v logu. Bez čitelného loggeru by se nedalo ověřit, že vůbec něco zaznamenala,
 * ani s jakým kontextem.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level:string, message:string, context:array<string,mixed>}> */
    private array $records = [];

    /** @param array<string,mixed> $context */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<array{level:string, message:string, context:array<string,mixed>}> */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Záznamy, jejichž zpráva obsahuje daný podřetězec.
     *
     * @return list<array{level:string, message:string, context:array<string,mixed>}>
     */
    public function matching(string $needle): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => str_contains($r['message'], $needle),
        ));
    }

    public function reset(): void
    {
        $this->records = [];
    }
}
