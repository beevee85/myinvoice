<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ProjectRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?array
    {
        // FORK 0923 (B2): client_id je nepovinné → LEFT JOIN; tenant nese
        // projects.supplier_id (COALESCE = fallback pro řádky před backfillem).
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.*, c.company_name AS client_company_name, c.main_email AS client_main_email,
                    COALESCE(p.supplier_id, c.supplier_id) AS supplier_id,
                    cur.code AS currency,
                    car.registration AS car_registration, car.vin AS car_vin,
                    car.name AS car_name, car.brand AS car_brand, car.model AS car_model
               FROM projects p
          LEFT JOIN clients   c   ON c.id   = p.client_id
          LEFT JOIN cars      car ON car.id = p.car_id
               JOIN currencies cur ON cur.id = p.currency_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->cast($row);
        $row['billing_emails'] = $this->billingEmailsFor($id);
        $row['participants'] = $this->participantsFor($id);
        return $row;
    }

    public function listForClient(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.name, p.status, p.currency_id, cur.code AS currency,
                    p.hourly_rate, p.payment_due_days, p.payment_due_unit, p.project_number,
                    p.contract_number, p.budget_total, p.budget_yearly, p.budget_monthly,
                    p.default_revenue_category_id, p.billing_emails_mode, p.archived_at
               FROM projects p
               JOIN currencies cur ON cur.id = p.currency_id
              WHERE p.client_id = ?
              ORDER BY p.archived_at IS NOT NULL, p.status = "active" DESC, p.name'
        );
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->cast($r), $rows);
    }

    public function listAll(array $filters = [], int $page = 1, int $perPage = 50, string $sort = 'name'): array
    {
        $where = ['p.archived_at IS NULL'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            // FORK 0923 (B2): tenant přes projects.supplier_id (fallback clients).
            $where[] = 'COALESCE(p.supplier_id, c.supplier_id) = ?';
            $params[] = (int) $filters['supplier_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'p.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM projects p LEFT JOIN clients c ON c.id = p.client_id WHERE $whereSql");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Whitelist řazení (defense proti SQLi přes user input)
        $orderBy = match ($sort) {
            'revenue'       => 'revenue DESC, p.name',
            'last_activity' => 'last_invoice_date IS NULL, last_invoice_date DESC, p.name',
            'client'        => 'c.company_name, p.name',
            default         => "p.status = 'active' DESC, p.name",
        };

        $offset = max(0, ($page - 1) * $perPage);
        // Cache `project_revenue_cache` per měnu projektu (přepočítáváno přes StatsRecomputer)
        $sql = "SELECT p.*, c.company_name AS client_company_name,
                       c.main_email AS client_main_email,
                       cur.code AS currency,
                       car.registration AS car_registration, car.vin AS car_vin,
                       COALESCE(prc.revenue, 0) AS revenue,
                       prc.last_invoice_date,
                       COALESCE(prc.invoice_count, 0) AS invoice_count
                  FROM projects p
             LEFT JOIN clients   c   ON c.id   = p.client_id
             LEFT JOIN cars      car ON car.id = p.car_id
                  JOIN currencies cur ON cur.id = p.currency_id
             LEFT JOIN project_revenue_cache prc ON prc.project_id = p.id AND prc.currency_id = p.currency_id
                 WHERE $whereSql
                 ORDER BY $orderBy
                 LIMIT ? OFFSET ?";
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $emailsByProject = $this->billingEmailsForMany(array_map(static fn (array $r) => (int) $r['id'], $rows));

        return [
            'data' => array_map(function (array $r) use ($emailsByProject) {
                $r = $this->cast($r);
                $r['billing_emails'] = $emailsByProject[(int) $r['id']] ?? [];
                return $r;
            }, $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function create(array $data): int
    {
        $pdo = $this->db->pdo();
        // FORK 0923 (B2): client_id je nepovinné — zakázka může začít nákupní
        // stranou (obchodní případ s vozidlem). Tenant nese projects.supplier_id:
        // buď explicitně z akce (middleware), nebo odvozený z klienta.
        $clientId = isset($data['client_id']) && (int) $data['client_id'] > 0 ? (int) $data['client_id'] : null;
        $supplierId = (int) ($data['supplier_id'] ?? 0);
        if ($clientId !== null) {
            $stmt = $pdo->prepare('SELECT supplier_id FROM clients WHERE id = ?');
            $stmt->execute([$clientId]);
            $clientSupplier = (int) $stmt->fetchColumn();
            if ($clientSupplier === 0) {
                throw new \InvalidArgumentException("Client #$clientId nenalezen.");
            }
            if ($supplierId !== 0 && $supplierId !== $clientSupplier) {
                throw new \InvalidArgumentException('Klient nepatří aktivnímu dodavateli.');
            }
            $supplierId = $clientSupplier;
        }
        if ($supplierId === 0) {
            throw new \InvalidArgumentException('Zakázka bez klienta vyžaduje supplier_id.');
        }

        $pdo->beginTransaction();
        try {
            $sql = 'INSERT INTO projects
                (supplier_id, client_id, car_id, name, payment_due_days, payment_due_unit, project_number, contract_number,
                 budget_total, budget_yearly, budget_monthly, hourly_rate, currency_id, status,
                 requires_work_report_approval, note, default_revenue_category_id, billing_emails_mode)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $supplierId,
                $clientId,
                $this->carIdFor($data, $supplierId),
                (string) $data['name'],
                (int) ($data['payment_due_days'] ?? 7),
                $this->nullablePaymentDueUnit($data, 'payment_due_unit'),
                $this->nullable($data, 'project_number'),
                $this->nullable($data, 'contract_number'),
                $this->nullableNumber($data, 'budget_total'),
                $this->nullableNumber($data, 'budget_yearly'),
                $this->nullableNumber($data, 'budget_monthly'),
                (float) ($data['hourly_rate'] ?? 1500),
                $this->resolveCurrencyId($data, $supplierId),
                (string) ($data['status'] ?? 'active'),
                !empty($data['requires_work_report_approval']) ? 1 : 0,
                $this->nullable($data, 'note'),
                $this->resolveRevenueCategoryId($data, $supplierId),
                $this->billingEmailsMode($data),
            ]);
            $id = (int) $pdo->lastInsertId();

            $this->saveBillingEmails($id, $data['billing_emails'] ?? []);
            if ($clientId !== null) {
                $this->upsertParticipant($id, $clientId, 'customer');
            }

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Vrací počet vydaných faktur, do kterých byla doplněna výchozí kategorie tržby
     * (backfill při nastavení/změně default_revenue_category_id). 0 = žádný backfill.
     */
    public function update(int $id, array $data): int
    {
        $pdo = $this->db->pdo();
        // Supplier lookup pro currency scope + aktuální default kategorie tržby.
        // FORK 0923: tenant z projects.supplier_id (fallback klient), LEFT JOIN.
        $stmt = $pdo->prepare('SELECT COALESCE(p.supplier_id, c.supplier_id) AS supplier_id, p.default_revenue_category_id
                                 FROM projects p LEFT JOIN clients c ON c.id = p.client_id WHERE p.id = ?');
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $supplierId = (int) ($cur['supplier_id'] ?? 0);
        $oldDefaultRevenueCategory = ($cur['default_revenue_category_id'] ?? null) !== null
            ? (int) $cur['default_revenue_category_id']
            : null;
        // Pokud klíč v payloadu chybí, zachovat aktuální (BC).
        $newDefaultRevenueCategory = array_key_exists('default_revenue_category_id', $data)
            ? $this->resolveRevenueCategoryId($data, $supplierId)
            : $oldDefaultRevenueCategory;

        $pdo->beginTransaction();
        try {
            $sql = 'UPDATE projects SET
                    name = ?, car_id = ?, payment_due_days = ?, payment_due_unit = ?, project_number = ?, contract_number = ?,
                    budget_total = ?, budget_yearly = ?, budget_monthly = ?, hourly_rate = ?,
                    currency_id = ?, status = ?, requires_work_report_approval = ?, note = ?,
                    default_revenue_category_id = ?, billing_emails_mode = ?
                    WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                (string) $data['name'],
                $this->carIdFor($data, $supplierId),
                (int) ($data['payment_due_days'] ?? 7),
                $this->nullablePaymentDueUnit($data, 'payment_due_unit'),
                $this->nullable($data, 'project_number'),
                $this->nullable($data, 'contract_number'),
                $this->nullableNumber($data, 'budget_total'),
                $this->nullableNumber($data, 'budget_yearly'),
                $this->nullableNumber($data, 'budget_monthly'),
                (float) ($data['hourly_rate'] ?? 1500),
                $this->resolveCurrencyId($data, $supplierId),
                (string) ($data['status'] ?? 'active'),
                !empty($data['requires_work_report_approval']) ? 1 : 0,
                $this->nullable($data, 'note'),
                $newDefaultRevenueCategory,
                $this->billingEmailsMode($data),
                $id,
            ]);

            $this->saveBillingEmails($id, $data['billing_emails'] ?? []);

            // Backfill: doplnit nově nastavenou kategorii do vydaných faktur zakázky,
            // které kategorii nemají vyplněnou (revenue_category_id IS NULL).
            $backfilled = 0;
            if ($newDefaultRevenueCategory !== null && $newDefaultRevenueCategory !== $oldDefaultRevenueCategory) {
                $bf = $pdo->prepare(
                    'UPDATE invoices SET revenue_category_id = ?
                      WHERE project_id = ? AND revenue_category_id IS NULL'
                );
                $bf->execute([$newDefaultRevenueCategory, $id]);
                $backfilled = $bf->rowCount();
            }

            $pdo->commit();
            return $backfilled;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function archive(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE projects SET archived_at = NOW() WHERE id = ?')->execute([$id]);
    }

    public function billingEmailsFor(int $projectId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT position, email, label, usages FROM project_billing_emails WHERE project_id = ? ORDER BY position'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => [
            'position' => (int) $r['position'],
            'email'    => $r['email'],
            'label'    => $r['label'],
            'usages'   => $this->decodeUsages($r['usages'] ?? null),
        ], $rows);
    }

    /**
     * @param int[] $projectIds
     * @return array<int, array<int, array{position:int,email:string,label:?string}>>
     */
    private function billingEmailsForMany(array $projectIds): array
    {
        if (!$projectIds) return [];
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT project_id, position, email, label, usages
               FROM project_billing_emails
              WHERE project_id IN ($placeholders)
              ORDER BY project_id, position"
        );
        $stmt->execute(array_map('intval', $projectIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = (int) $r['project_id'];
            $out[$pid][] = [
                'position' => (int) $r['position'],
                'email'    => $r['email'],
                'label'    => $r['label'],
                'usages'   => $this->decodeUsages($r['usages'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Účely e-mailu zakázky (#86): NULL = všechny typy zpráv (default).
     * V API reprezentujeme NULL — frontend zobrazí vše zaškrtnuté.
     *
     * @return list<string>|null
     */
    private function decodeUsages(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') return null;
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || $decoded === []) return null;
        return array_values(array_filter(array_map('strval', $decoded), static fn ($u) => $u !== ''));
    }

    private function saveBillingEmails(int $projectId, array $emails): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM project_billing_emails WHERE project_id = ?')->execute([$projectId]);

        $stmt = $pdo->prepare(
            'INSERT INTO project_billing_emails (project_id, position, email, label, usages) VALUES (?, ?, ?, ?, ?)'
        );
        $validUsages = ['documents', 'reminders', 'approvals'];
        foreach ($emails as $entry) {
            if (!is_array($entry)) continue;
            $email = trim((string) ($entry['email'] ?? ''));
            $position = (int) ($entry['position'] ?? 0);
            if ($email === '' || $position < 1 || $position > 3) continue;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $label = trim((string) ($entry['label'] ?? '')) ?: null;
            // Účely (#86): pole stringů; NULL / prázdné / všechny tři = NULL (všechny typy).
            $usages = null;
            if (isset($entry['usages']) && is_array($entry['usages'])) {
                $filtered = array_values(array_unique(array_filter(
                    array_map(static fn ($u) => trim((string) $u), $entry['usages']),
                    static fn ($u) => in_array($u, $validUsages, true),
                )));
                if ($filtered !== [] && count($filtered) < count($validUsages)) {
                    $usages = json_encode($filtered, JSON_UNESCAPED_UNICODE);
                }
            }
            $stmt->execute([$projectId, $position, $email, $label, $usages]);
        }
    }

    // ── FORK 0923 (B2): zakázka napříč nákupem a prodejem ────────────────

    /** Vozidlo případu — validace tenanta; NULL/0 = bez vozidla. */
    private function carIdFor(array $data, int $supplierId): ?int
    {
        $raw = $data['car_id'] ?? null;
        if ($raw === null || $raw === '' || (int) $raw === 0) {
            return null;
        }
        $carId = (int) $raw;
        $check = $this->db->pdo()->prepare('SELECT 1 FROM cars WHERE id = ? AND supplier_id = ?');
        $check->execute([$carId, $supplierId]);
        if (!$check->fetchColumn()) {
            throw new \InvalidArgumentException("Vozidlo #$carId nepatří tomuto tenantovi.");
        }
        return $carId;
    }

    /** Protistrana zakázky (customer/vendor); INSERT IGNORE = idempotentní. */
    public function upsertParticipant(int $projectId, int $clientId, string $role): void
    {
        if (!in_array($role, ['customer', 'vendor'], true)) {
            return;
        }
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO project_participants (project_id, client_id, role) VALUES (?, ?, ?)'
        )->execute([$projectId, $clientId, $role]);
    }

    /**
     * Protistrany zakázky — evidované + živě dopočtené z přiřazených dokladů
     * (dodavatelé z přijatých faktur, odběratelé z vydaných). Dopočtené se
     * zároveň idempotentně zapíší, ať tabulka drží krok s realitou.
     *
     * @return list<array{client_id:int, role:string, company_name:string}>
     */
    public function participantsFor(int $projectId): array
    {
        $pdo = $this->db->pdo();
        // Živá derivace z dokladů → upsert (INSERT IGNORE, levné a idempotentní).
        $pdo->prepare(
            "INSERT IGNORE INTO project_participants (project_id, client_id, role)
             SELECT DISTINCT i.project_id, i.client_id, 'customer'
               FROM invoices i WHERE i.project_id = ? AND i.deleted_at IS NULL"
        )->execute([$projectId]);
        $pdo->prepare(
            "INSERT IGNORE INTO project_participants (project_id, client_id, role)
             SELECT DISTINCT pi.project_id, pi.vendor_id, 'vendor'
               FROM purchase_invoices pi WHERE pi.project_id = ? AND pi.deleted_at IS NULL"
        )->execute([$projectId]);

        $stmt = $pdo->prepare(
            'SELECT pp.client_id, pp.role, c.company_name
               FROM project_participants pp
               JOIN clients c ON c.id = pp.client_id
              WHERE pp.project_id = ?
              ORDER BY pp.role, c.company_name'
        );
        $stmt->execute([$projectId]);
        return array_map(static fn (array $r): array => [
            'client_id'    => (int) $r['client_id'],
            'role'         => (string) $r['role'],
            'company_name' => (string) $r['company_name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * D5 — obchodní případ: nákladová a výnosová strana zakázky + marže.
     *
     * Náklad: přijaté doklady zakázky mimo koš, mimo draft/cancelled a VŽDY mimo
     * zálohy (advance nese jen platební rovinu — náklad nese DDKPZ/konečná;
     * shodné s měsíčními součty a dashboardem, migrace 0906).
     * Výnos: vydané faktury/dobropisy/DD k platbě mimo koš ve stavech
     * issued/sent/reminded/paid (shodné s obratem seznamu).
     * Marže se počítá z částek bez DPH (skutečný výsledek plátce) a pro
     * kontrolu se vrací i vč. DPH (tak ji uvádí zadání D5).
     *
     * @return array{purchase_without_vat:float, purchase_with_vat:float,
     *               sale_without_vat:float, sale_with_vat:float,
     *               margin_without_vat:float, margin_with_vat:float,
     *               margin_pct:?float, purchase_count:int, sale_count:int}
     */
    public function caseSummary(int $projectId): array
    {
        $pdo = $this->db->pdo();
        $p = $pdo->prepare(
            "SELECT COALESCE(SUM(total_without_vat), 0) AS net,
                    COALESCE(SUM(total_with_vat + COALESCE(rounding, 0)), 0) AS gross,
                    COUNT(*) AS n
               FROM purchase_invoices
              WHERE project_id = ? AND deleted_at IS NULL
                AND status NOT IN ('draft', 'cancelled')
                AND document_kind <> 'advance'"
        );
        $p->execute([$projectId]);
        $purchase = $p->fetch(PDO::FETCH_ASSOC) ?: ['net' => 0, 'gross' => 0, 'n' => 0];

        $s = $pdo->prepare(
            "SELECT COALESCE(SUM(total_without_vat), 0) AS net,
                    COALESCE(SUM(total_with_vat), 0) AS gross,
                    COUNT(*) AS n
               FROM invoices
              WHERE project_id = ? AND deleted_at IS NULL
                AND status IN ('issued', 'sent', 'reminded', 'paid')
                AND invoice_type IN ('invoice', 'credit_note', 'tax_document')"
        );
        $s->execute([$projectId]);
        $sale = $s->fetch(PDO::FETCH_ASSOC) ?: ['net' => 0, 'gross' => 0, 'n' => 0];

        $purchaseNet = round((float) $purchase['net'], 2);
        $saleNet = round((float) $sale['net'], 2);
        $marginNet = round($saleNet - $purchaseNet, 2);

        return [
            'purchase_without_vat' => $purchaseNet,
            'purchase_with_vat'    => round((float) $purchase['gross'], 2),
            'sale_without_vat'     => $saleNet,
            'sale_with_vat'        => round((float) $sale['gross'], 2),
            'margin_without_vat'   => $marginNet,
            'margin_with_vat'      => round((float) $sale['gross'] - (float) $purchase['gross'], 2),
            'margin_pct'           => $purchaseNet > 0 ? round($marginNet / $purchaseNet * 100, 1) : null,
            'purchase_count'       => (int) $purchase['n'],
            'sale_count'           => (int) $sale['n'],
        ];
    }

    private function cast(array $row): array
    {
        if (isset($row['id']))               $row['id'] = (int) $row['id'];
        if (isset($row['client_id']))        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        if (array_key_exists('car_id', $row)) $row['car_id'] = $row['car_id'] !== null ? (int) $row['car_id'] : null;
        if (isset($row['supplier_id']))      $row['supplier_id'] = (int) $row['supplier_id'];
        if (isset($row['payment_due_days'])) $row['payment_due_days'] = (int) $row['payment_due_days'];
        if (isset($row['hourly_rate']))      $row['hourly_rate'] = (float) $row['hourly_rate'];
        foreach (['budget_total', 'budget_yearly', 'budget_monthly'] as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = $row[$f] !== null ? (float) $row[$f] : null;
            }
        }
        if (isset($row['currency_id']))      $row['currency_id'] = (int) $row['currency_id'];
        if (isset($row['requires_work_report_approval'])) {
            $row['requires_work_report_approval'] = (bool) $row['requires_work_report_approval'];
        }
        if (array_key_exists('revenue', $row)) {
            $row['revenue'] = (float) $row['revenue'];
        }
        if (array_key_exists('last_invoice_date', $row)) {
            $row['last_invoice_date'] = $row['last_invoice_date'] ?: null;
        }
        if (array_key_exists('default_revenue_category_id', $row)) {
            $row['default_revenue_category_id'] = $row['default_revenue_category_id'] !== null
                ? (int) $row['default_revenue_category_id']
                : null;
        }
        return $row;
    }

    /**
     * Validace výchozí kategorie tržby z payloadu. Vrací int id nebo null.
     * NULL / 0 / prázdné → null. Jinak ověří, že kategorie patří danému tenantovi.
     * Symetrie k ClientRepository::resolveRevenueCategoryId.
     */
    private function resolveRevenueCategoryId(array $data, int $supplierId): ?int
    {
        if (!array_key_exists('default_revenue_category_id', $data)) {
            return null;
        }
        $raw = $data['default_revenue_category_id'];
        if ($raw === null || $raw === '' || (int) $raw === 0) {
            return null;
        }
        $catId = (int) $raw;
        $check = $this->db->pdo()->prepare(
            'SELECT 1 FROM revenue_categories WHERE id = ? AND supplier_id = ?'
        );
        $check->execute([$catId, $supplierId]);
        if (!$check->fetchColumn()) {
            throw new \InvalidArgumentException("Kategorie tržby #$catId nepatří tomuto tenantovi.");
        }
        return $catId;
    }

    /**
     * Resolve currency_id z `currency_id` (preferováno) nebo z `currency` (legacy code lookup).
     * Default = výchozí (is_default=1) řádek pro CZK v rámci supplier.
     * Pokud je dáno explicitní currency_id, ověří že patří danému supplier (anti cross-supplier).
     */
    private function resolveCurrencyId(array $data, int $supplierId): int
    {
        if (isset($data['currency_id'])) {
            $id = (int) $data['currency_id'];
            $check = $this->db->pdo()->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
            $check->execute([$id, $supplierId]);
            if (!$check->fetchColumn()) {
                throw new \InvalidArgumentException("Currency #$id nepatří supplier #$supplierId.");
            }
            return $id;
        }
        $code = strtoupper((string) ($data['currency'] ?? 'CZK'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException("Currency not found: $code (supplier #$supplierId)");
        }
        return (int) $id;
    }

    private function nullable(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function nullableNumber(array $data, string $key): ?float
    {
        $v = $data[$key] ?? null;
        if ($v === null || $v === '') return null;
        return (float) $v;
    }

    /** Splatnost zakázky unit. NULL = dny (BC). Validuje 'days'|'month'. */
    private function nullablePaymentDueUnit(array $data, string $key): ?string
    {
        $v = $this->nullable($data, $key);
        if ($v === null) return null;
        if (!in_array($v, ['days', 'month'], true)) {
            throw new \InvalidArgumentException("{$key} musí být 'days' nebo 'month'.");
        }
        return $v;
    }

    /**
     * Režim kombinace e-mailů zakázky s kontakty klienta (#86).
     * Default 'auto' = dosavadní per-typ chování (viz RecipientResolver).
     */
    private function billingEmailsMode(array $data): string
    {
        $v = trim((string) ($data['billing_emails_mode'] ?? 'auto'));
        if ($v === '') return 'auto';
        if (!in_array($v, ['auto', 'append', 'replace'], true)) {
            throw new \InvalidArgumentException("billing_emails_mode musí být 'auto', 'append' nebo 'replace'.");
        }
        return $v;
    }
}
