# CUSTOMIZATIONS.md — evidence vlastních úprav této instalace

Instalace: `faktury.betka.eu`, VPS, `/opt/myinvoice`. Pravidla práce viz `CLAUDE.md` (gitignored, jen na serveru).

Po každém updatu z upstreamu projdi celý seznam níže a ověř, že žádná úprava tiše nevypadla.

---

## 2026-07-02 — FÁZE 1: pole „Poznámka nad položkami" nad sekcí položek

**Co se změnilo:** v editoru faktury je pole „Poznámka nad položkami" přesunuto ze spodního bloku „Sumace + poznámky" nahoru — jako samostatný box těsně **nad** sekci Položky. Editor tak odpovídá pořadí na tiskovém PDF. „Poznámka pod položkami" zůstává dole.

**Které soubory:** `web/src/pages/invoices/InvoiceEditor.vue` (jen přesun bloku v šabloně, +6/−4 řádků, žádná změna logiky ani API). Commit `feat(invoice-editor): přesuň pole „Poznámka nad položkami" nad sekci položek`.

**Proč:** požadavek uživatele — pole se týká obsahu nad položkami, ale zadávalo se až pod nimi.

**Jak ověřit po merge:** otevřít editor faktury (nová i editace stávající) → box „Poznámka nad položkami" je nad sekcí Položky, text se ukládá a tiskne na PDF nad tabulkou položek. Pozor při upstream merge: upstream verzi tohoto bloku v „Sumace + poznámky" znovu nepřidat (konflikt řešit ve prospěch přesunuté pozice).

## 2026-07-02 — FÁZE 0: přepnutí provozu na build ze zdrojáku

**Co se změnilo:**
- Provoz přepnut z GHCR image (`ghcr.io/radekhulan/myinvoice:latest`, `docker-compose.production.yml`) na lokální build ze zdrojáku (`docker-compose.yml`, `Dockerfile.alpine`, image `myinvoice:latest`).
- Aktivní compose je od teď `docker-compose.yml`. Port drží `.env` (`APP_PORT=127.0.0.1:8090`) — **neměnit**, visí na tom reverzní proxy.
- Git: pracovní větev `custom`, založená z tagu `v4.41.0` (= verze, která běžela z GHCR; DB migrace 0001–0119 aplikované). Remotes: `origin` = `git@github.com:beevee85/myinvoice.git` (fork), `upstream` = autorův repozitář.
- Volumes beze změny: `myinvoice_app-data`, `myinvoice_db-data`. Konfigurace beze změny: `cfg.docker.php` (mount) + `cfg.local.php` (v `/data`).

**Které soubory:** žádná změna kódu aplikace. Commit `chore(cmd): nastav spustitelný bit na .sh skriptech (provoz na VPS)` — jen exec bity na `cmd/*.sh` (mode 100644 → 100755, 9 souborů).

**Proč:** příprava na vlastní úpravy kódu (CESTA B dle CLAUDE.md) — fork + build ze zdrojáku umožňuje vlastní změny a zároveň braní updatů od autora.

**Jak ověřit po merge/rebuildu:**
1. `docker compose -f docker-compose.yml build && docker compose -f docker-compose.yml up -d`
2. `docker ps` — app běží z image `myinvoice:latest`, port `127.0.0.1:8090->80`
3. `curl -I http://127.0.0.1:8090` → HTTP 200
4. `docker compose -f docker-compose.yml exec app cat /var/www/html/VERSION` odpovídá očekávané verzi
5. `docker compose -f docker-compose.yml exec app php api/bin/migrate.php --status` — žádná nevyřízená migrace
6. `cmd/*.sh` mají exec bit (`ls -l cmd/`)

**Zálohy před změnou:** `/root/backup-myinvoice-db-2026-07-02-0122.sql`, `/root/backup-myinvoice-data-2026-07-02-0123.tar.gz`.

**Rollback:** `docker compose -f docker-compose.production.yml up -d` (poslední GHCR image).
