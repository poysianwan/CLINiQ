<?php

function ensure_visit_workflow_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = db();
    $stmt = $db->query("SHOW COLUMNS FROM clinic_visits");
    $columns = [];
    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['Field']] = $column;
    }

    $addColumn = function (string $name, string $definition) use ($db, &$columns): void {
        if (!isset($columns[$name])) {
            $db->exec("ALTER TABLE clinic_visits ADD COLUMN {$name} {$definition}");
            $columns[$name] = ['Field' => $name];
        }
    };

    $addColumn('status', "ENUM('Unaddressed','Active','Completed','Cancelled') NOT NULL DEFAULT 'Unaddressed' AFTER risk_score");
    $addColumn('visit_purpose', "VARCHAR(80) NULL AFTER status");
    $addColumn('visit_source', "ENUM('Self Logbook','Staff Recorded','Nurse Emergency') NOT NULL DEFAULT 'Staff Recorded' AFTER visit_purpose");
    $addColumn('attended_by', "INT NULL AFTER recorded_by");
    $addColumn('updated_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

    $db->exec("
        CREATE TABLE IF NOT EXISTS visit_treatment_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            visit_id INT NOT NULL,
            symptoms_note TEXT NULL,
            diagnosis TEXT NULL,
            management_treatment TEXT NULL,
            referral_type VARCHAR(120) NULL,
            remarks TEXT NULL,
            amendment_reason TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (visit_id) REFERENCES clinic_visits(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    $entryStmt = $db->query("SHOW COLUMNS FROM visit_treatment_entries");
    $entryColumns = [];
    foreach ($entryStmt->fetchAll() as $column) {
        $entryColumns[$column['Field']] = $column;
    }
    if (!isset($entryColumns['amendment_reason'])) {
        $db->exec("ALTER TABLE visit_treatment_entries ADD COLUMN amendment_reason TEXT NULL AFTER remarks");
    }

    backfill_legacy_visit_statuses($db);
    $db->exec("
        UPDATE clinic_visits
        SET attended_by = recorded_by
        WHERE attended_by IS NULL
          AND recorded_by IS NOT NULL
          AND status IN ('Active', 'Completed')
    ");

    $ready = true;
}

function backfill_legacy_visit_statuses(PDO $db): void
{
    $totals = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status <> 'Unaddressed' THEN 1 ELSE 0 END) AS with_status
        FROM clinic_visits
    ")->fetch();

    if ((int) ($totals['total'] ?? 0) === 0 || (int) ($totals['with_status'] ?? 0) > 0) {
        return;
    }

    $db->exec("
        UPDATE clinic_visits
        SET status = CASE
            WHEN LOWER(COALESCE(action_taken, '')) LIKE '%cancel%' THEN 'Cancelled'
            WHEN visit_source = 'Nurse Emergency' OR risk_level IN ('Critical', 'High') THEN 'Active'
            WHEN COALESCE(action_taken, '') <> '' AND LOWER(action_taken) NOT LIKE '%awaiting%' THEN 'Completed'
            ELSE 'Unaddressed'
        END
    ");
}

function visit_statuses(): array
{
    return ['Unaddressed', 'Active', 'Completed', 'Cancelled'];
}

function visit_purposes(): array
{
    return ['Medical Consult', 'Health Monitoring', 'Pain Management', 'Dental Consult', 'Wound Care', 'APE', 'Emergency', 'Other'];
}

function visit_sources(): array
{
    return ['Self Logbook', 'Staff Recorded', 'Nurse Emergency'];
}

function visit_referral_options(): array
{
    return ['None', 'Advised to Go Home', 'Barangay Health Center', 'Public Hospital', 'Private Hospital', 'Specialist Referral'];
}

function visit_status_badge_class(string $status): string
{
    return match ($status) {
        'Active' => 'badge-active',
        'Completed' => 'badge-completed',
        'Cancelled' => 'badge-cancelled',
        default => 'badge-pending',
    };
}

function normalize_visit_status(?string $status, string $fallback = 'Active'): string
{
    $status = trim((string) $status);
    return in_array($status, visit_statuses(), true) ? $status : $fallback;
}

function normalize_visit_purpose(?string $purpose): ?string
{
    $purpose = trim((string) $purpose);
    return $purpose === '' ? null : mb_substr($purpose, 0, 80);
}

function normalize_visit_source(?string $source, string $fallback = 'Staff Recorded'): string
{
    $source = trim((string) $source);
    return in_array($source, visit_sources(), true) ? $source : $fallback;
}

function treatment_entry_has_content(array $data): bool
{
    foreach (['symptoms_note', 'diagnosis', 'management_treatment', 'referral_type', 'remarks'] as $key) {
        if (trim((string) ($data[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}
