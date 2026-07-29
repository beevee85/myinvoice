<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * FORK (beevee85) — runtime pojistka proti síťovým voláním v testu.
 *
 * Nahradí systémové stream wrappery pro `http://` a `https://` takovým, který na
 * jakýkoli pokus o otevření vyhodí výjimku. Použití: obalit kód, který má být offline,
 * a ověřit, že proběhl.
 *
 * CO CHYTÍ: `file_get_contents`, `fopen`, `simplexml_load_file`, `DOMDocument::load`
 * a vše ostatní, co jde přes PHP streamy — tedy nejběžnější způsob, jak se do kódu
 * omylem dostane síť.
 *
 * CO NECHYTÍ: volání přes ext-curl (Guzzle ve výchozím nastavení). Proto tahle kontrola
 * nestojí sama — doplňuje ji statická kontrola zdrojáku, která hlídá, že tam žádný
 * HTTP klient ani curl vůbec není. Ani jedna z nich není úplná; obě dohromady pokrývají
 * jak „někdo přidal file_get_contents", tak „někdo si injektoval klienta".
 */
final class NetworkBlockingStreamWrapper
{
    /** @var resource|null */
    public $context;

    private const PROTOCOLS = ['http', 'https'];

    /** Spustí `$work` s odstřiženou sítí a vrátí jeho výsledek. */
    public static function assertOffline(callable $work): mixed
    {
        self::enable();
        try {
            return $work();
        } finally {
            self::disable();
        }
    }

    public static function enable(): void
    {
        foreach (self::PROTOCOLS as $protocol) {
            @stream_wrapper_unregister($protocol);
            stream_wrapper_register($protocol, self::class);
        }
    }

    public static function disable(): void
    {
        foreach (self::PROTOCOLS as $protocol) {
            @stream_wrapper_unregister($protocol);
            @stream_wrapper_restore($protocol);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        throw new \RuntimeException("Zakázané síťové volání v offline testu: {$path}");
    }

    public function url_stat(string $path, int $flags): array|false
    {
        throw new \RuntimeException("Zakázané síťové volání v offline testu: {$path}");
    }
}
