<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Compliance\ComplianceFlags;
use PDO;

/**
 * FORK 0925 — trvalé compliance příznaky (Dokument 5 §3, Dokument 7 §2).
 *
 * ZÁMĚRNĚ NEEXISTUJE ŽÁDNÁ MAZACÍ METODA. Příznak se nikdy neodstraňuje — mění
 * se jen stavová/odbavovací pole. Sloupce type/severity/detected_at/context/
 * legal_reference jsou po vytvoření neměnné (žádný UPDATE se jich nedotýká).
 * Hlídá ComplianceFlagGuardTest (sken zdrojů i migrací na mazací SQL).
 */
final class ComplianceFlagRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Založí příznak. Duplicita (týž typ + subjekt ve stavu open/acknowledged)
     * se nezakládá znovu — vrací existující id (Dokument 5 §4.3: modál jednou,
     * pak už jen svítí).
     *
     * @param array{type:string, subject_type:string, subject_id:int, message:string,
     *              context?:array, project_id?:?int, client_id?:?int,
     *              settlement_group_id?:?int, car_vin?:?string, origin?:string,
     *              deadline?:?string, legal_reference?:?string} $data
     */
    public function create(int $supplierId, array $data): int
    {
        $type = (string) $data['type'];
        if (!ComplianceFlags::isKnown($type)) {
            throw new \InvalidArgumentException("Neznámý typ příznaku: {$type}");
        }

        $existing = $this->findOpen($supplierId, $type, (string) $data['subject_type'], (int) $data['subject_id']);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO compliance_flags
                (supplier_id, type, severity, status, origin, detected_rule,
                 subject_type, subject_id, project_id, client_id, settlement_group_id, car_vin,
                 context, message, legal_reference, deadline)
             VALUES (?, ?, ?, "open", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $type,
            ComplianceFlags::severity($type),
            in_array($data['origin'] ?? '', ['document_save', 'batch', 'manual', 'import'], true)
                ? $data['origin'] : 'document_save',
            ComplianceFlags::rule($type),
            (string) $data['subject_type'],
            (int) $data['subject_id'],
            isset($data['project_id']) && (int) $data['project_id'] > 0 ? (int) $data['project_id'] : null,
            isset($data['client_id']) && (int) $data['client_id'] > 0 ? (int) $data['client_id'] : null,
            isset($data['settlement_group_id']) && (int) $data['settlement_group_id'] > 0 ? (int) $data['settlement_group_id'] : null,
            isset($data['car_vin']) && $data['car_vin'] !== '' ? (string) $data['car_vin'] : null,
            isset($data['context']) && is_array($data['context'])
                ? json_encode($data['context'], JSON_UNESCAPED_UNICODE) : null,
            (string) $data['message'],
            $data['legal_reference'] ?? ComplianceFlags::legalReference($type),
            $data['deadline'] ?? null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findOpen(int $supplierId, string $type, string $subjectType, int $subjectId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM compliance_flags
              WHERE supplier_id = ? AND type = ? AND subject_type = ? AND subject_id = ?
                AND status IN ('open', 'acknowledged', 'explained')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $type, $subjectType, $subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->cast($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cf.*, c.company_name AS client_company_name, p.name AS project_name,
                    u.name AS acknowledged_by_name
               FROM compliance_flags cf
          LEFT JOIN clients c ON c.id = cf.client_id
          LEFT JOIN projects p ON p.id = cf.project_id
          LEFT JOIN users u ON u.id = cf.acknowledged_by
              WHERE cf.id = ? AND cf.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->cast($row) : null;
    }

    /**
     * Seznam s filtry (Dokument 7 §4): severity, status, type, klient, rok.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, array $filters = []): array
    {
        $where = ['cf.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['severity']) && in_array($filters['severity'], ['high', 'medium', 'low'], true)) {
            $where[] = 'cf.severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'unresolved') {
                $where[] = "cf.status IN ('open', 'acknowledged', 'explained')";
            } elseif (in_array($filters['status'], ['open', 'acknowledged', 'explained', 'resolved', 'superseded'], true)) {
                $where[] = 'cf.status = ?';
                $params[] = $filters['status'];
            }
        }
        if (!empty($filters['type']) && ComplianceFlags::isKnown((string) $filters['type'])) {
            $where[] = 'cf.type = ?';
            $params[] = (string) $filters['type'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'cf.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(cf.detected_at) = ?';
            $params[] = (int) $filters['year'];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT cf.*, c.company_name AS client_company_name, p.name AS project_name,
                    p.car_id AS project_car_id, car.vin AS project_car_vin,
                    car.registration AS project_car_registration,
                    u.name AS acknowledged_by_name
               FROM compliance_flags cf
          LEFT JOIN clients c ON c.id = cf.client_id
          LEFT JOIN projects p ON p.id = cf.project_id
          LEFT JOIN cars car ON car.id = p.car_id
          LEFT JOIN users u ON u.id = cf.acknowledged_by
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY cf.detected_at DESC, cf.id DESC
              LIMIT 500'
        );
        $stmt->execute($params);
        return array_map($this->cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Dlaždice + badge (Dokument 7 §1/§4): počty NEODBAVENÝCH dle závažnosti
     * (badge se počítá jen z open — jinak po roce ukazuje trojciferné číslo),
     * lhůty do 30 dnů, odbaveno celkem.
     *
     * @return array{open_high:int, open_medium:int, open_low:int,
     *               deadlines_30:int, handled_total:int}
     */
    public function summary(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN status = 'open' AND severity = 'high' THEN 1 ELSE 0 END) AS open_high,
                SUM(CASE WHEN status = 'open' AND severity = 'medium' THEN 1 ELSE 0 END) AS open_medium,
                SUM(CASE WHEN status = 'open' AND severity = 'low' THEN 1 ELSE 0 END) AS open_low,
                SUM(CASE WHEN status IN ('open','acknowledged') AND deadline IS NOT NULL
                          AND deadline <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS deadlines_30,
                SUM(CASE WHEN status <> 'open' THEN 1 ELSE 0 END) AS handled_total
               FROM compliance_flags WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'open_high'     => (int) ($r['open_high'] ?? 0),
            'open_medium'   => (int) ($r['open_medium'] ?? 0),
            'open_low'      => (int) ($r['open_low'] ?? 0),
            'deadlines_30'  => (int) ($r['deadlines_30'] ?? 0),
            'handled_total' => (int) ($r['handled_total'] ?? 0),
        ];
    }

    /**
     * Odbavení (jediná povolená mutace): volba + volitelné/povinné zdůvodnění.
     * Příznak NEMIZÍ — jen mění stav; hromadné odbavení neexistuje (Dokument 7 §10).
     */
    public function acknowledge(int $supplierId, int $id, int $userId, string $userRole, string $choice, ?string $note, string $newStatus = 'acknowledged'): bool
    {
        if (!in_array($newStatus, ['acknowledged', 'explained', 'resolved'], true)) {
            throw new \InvalidArgumentException('Neplatný cílový stav.');
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE compliance_flags
                SET status = ?, acknowledged_by = ?, acknowledged_at = NOW(),
                    acknowledged_role = ?, acknowledgement_choice = ?, acknowledgement_note = ?
              WHERE id = ? AND supplier_id = ? AND status IN ("open", "acknowledged")'
        );
        $stmt->execute([$newStatus, $userId, $userRole, $choice, $note, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Systémové zneaktuálnění (oprava zdrojového údaje) — uživatel tuto cestu nemá. */
    public function supersede(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE compliance_flags SET status = "superseded" WHERE id = ? AND supplier_id = ?'
        )->execute([$id, $supplierId]);
    }

    private function cast(array $r): array
    {
        foreach (['id', 'supplier_id', 'subject_id', 'project_id', 'client_id',
                  'settlement_group_id', 'acknowledged_by', 'project_car_id'] as $f) {
            if (array_key_exists($f, $r)) {
                $r[$f] = $r[$f] !== null ? (int) $r[$f] : null;
            }
        }
        if (array_key_exists('context', $r)) {
            $decoded = is_string($r['context']) && $r['context'] !== '' ? json_decode($r['context'], true) : null;
            $r['context'] = is_array($decoded) ? $decoded : null;
        }
        return $r;
    }
}
