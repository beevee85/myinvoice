# Lokální fixtures — OBSAH SE NIKDY NECOMMITUJE

Sem patří **reálné doklady třetích stran** používané k ručnímu ověření
(např. sada TUkas). Adresář je celý v `.gitignore` kromě `.gitkeep` a tohohle
souboru — všechno ostatní tu smí ležet jen lokálně.

## Proč

Doklady od dodavatelů obsahují IČO, bankovní účty, VIN, jména a částky. Do gitu
nepatří ani ony, ani jejich anonymizované odvozeniny obrázků (z těch jde původní
obsah často rekonstruovat).

## Jak s tím pracovat

* **Verzované fixtures musí být syntetické** — vymyšlené subjekty, IČO s platnou
  kontrolní číslicí mod 11, `example.invalid` e-maily. Vzor: `api/bin/test-seed-clients.php`.
* Testy nad reálnými doklady patří do skupiny `local` a musí se **přeskočit**,
  když soubory nejsou:

```php
if (!is_file(__DIR__ . '/../fixtures/local/faktura.pdf')) {
    self::markTestSkipped('Lokální fixture není k dispozici — test je ve skupině `local`.');
}
```

* Ověř před commitem: `git status --porcelain api/tests/fixtures/local/` musí být prázdné.
