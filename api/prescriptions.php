<?php
// GET /api/prescriptions  — role-scoped paginated list
// POST /api/prescriptions — create (health worker only)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── Shared helper: build a prescription response object from a DB row + items array ──
function formatPrescriptionRow(array $row, array $items = []): array
{
    return [
        'id'              => $row['id'],
        'patientName'     => $row['patientName'],
        'patientAge'      => (int) $row['patientAge'],
        'patientGender'   => $row['patientGender'],
        'chiefComplaints' => $row['chiefComplaints'],
        'onExamination'   => $row['onExamination'],
        'advice'          => $row['advice'],
        'status'          => $row['status'],
        'reviewedAt'      => $row['reviewedAt'],
        'reviewNotes'     => $row['reviewNotes'],
        'createdAt'       => $row['createdAt'],
        'updatedAt'       => $row['updatedAt'],
        'healthWorker'    => ['id' => $row['hwId'], 'name' => $row['hwName']],
        'reviewedBy'      => $row['reviewerId']
            ? ['id' => $row['reviewerId'], 'name' => $row['reviewerName']]
            : null,
        'items'           => $items,
    ];
}

try {

    // ── GET /api/prescriptions ─────────────────────────────────────────────────
    if ($method === 'GET') {
        $user = requireActiveUser();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        // Role-scoped filter
        if ($user['role'] === 'HEALTH_WORKER') {
            $where[]  = 'p.health_worker_id = ?';
            $params[] = $user['id'];

        } elseif ($user['role'] === 'DOCTOR') {
            $stmt = $pdo->prepare(
                "SELECT health_worker_id FROM doctor_assignments WHERE doctor_id = ?"
            );
            $stmt->execute([$user['id']]);
            $hwIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($hwIds)) {
                ok([], paginate($page, $limit, 0));
            }

            $ph      = implode(',', array_fill(0, count($hwIds), '?'));
            $where[] = "p.health_worker_id IN ({$ph})";
            $params  = array_merge($params, $hwIds);
        }
        // ADMIN: no additional filter

        if ($status !== '') {
            $where[]  = 'p.status = ?';
            $params[] = $status;
        }

        if ($search !== '') {
            $like     = '%' . $search . '%';
            $where[]  = '(p.patient_name LIKE ? OR p.chief_complaints LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Count total
        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM prescriptions p {$whereClause}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch page
        $listStmt = $pdo->prepare(
            "SELECT p.id,
                    p.patient_name      AS patientName,
                    p.patient_age       AS patientAge,
                    p.patient_gender    AS patientGender,
                    p.chief_complaints  AS chiefComplaints,
                    p.on_examination    AS onExamination,
                    p.advice,
                    p.status,
                    p.reviewed_at       AS reviewedAt,
                    p.review_notes      AS reviewNotes,
                    p.created_at        AS createdAt,
                    p.updated_at        AS updatedAt,
                    hw.id               AS hwId,
                    hw.name             AS hwName,
                    rv.id               AS reviewerId,
                    rv.name             AS reviewerName
             FROM prescriptions p
             JOIN users hw ON p.health_worker_id = hw.id
             LEFT JOIN users rv ON p.reviewed_by_id = rv.id
             {$whereClause}
             ORDER BY p.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $listStmt->execute(array_merge($params, [$limit, $offset]));
        $rows = $listStmt->fetchAll();

        // Fetch items for all prescriptions in one query
        $itemsByPrescription = [];
        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $itemStmt = $pdo->prepare(
                "SELECT pi.id,
                        pi.prescription_id,
                        pi.dose, pi.frequency, pi.duration, pi.instructions,
                        m.id   AS medicineId,
                        m.name AS medicineName,
                        m.form AS medicineForm
                 FROM prescription_items pi
                 JOIN medicines m ON pi.medicine_id = m.id
                 WHERE pi.prescription_id IN ({$ph})"
            );
            $itemStmt->execute($ids);
            foreach ($itemStmt->fetchAll() as $item) {
                $itemsByPrescription[$item['prescription_id']][] = [
                    'id'           => $item['id'],
                    'dose'         => $item['dose'],
                    'frequency'    => $item['frequency'],
                    'duration'     => $item['duration'],
                    'instructions' => $item['instructions'],
                    'medicine'     => [
                        'id'   => $item['medicineId'],
                        'name' => $item['medicineName'],
                        'form' => $item['medicineForm'],
                    ],
                ];
            }
        }

        $prescriptions = array_map(
            fn($row) => formatPrescriptionRow($row, $itemsByPrescription[$row['id']] ?? []),
            $rows
        );

        ok($prescriptions, paginate($page, $limit, $total));
        return;
    }

    // ── POST /api/prescriptions ────────────────────────────────────────────────
    if ($method === 'POST') {
        $user = requireActiveUser();

        if ($user['role'] !== 'HEALTH_WORKER') {
            forbidden('Only health workers can create prescriptions');
        }
        if (!(bool) $user['can_write_prescription']) {
            forbidden('Your prescription writing permission has been revoked. Contact admin.');
        }

        $body = getJsonInput();

        // Validate required fields
        $errors = [];
        $patientName     = trim((string)($body['patientName'] ?? ''));
        $patientAge      = $body['patientAge'] ?? null;
        $patientGender   = trim((string)($body['patientGender'] ?? ''));
        $chiefComplaints = trim((string)($body['chiefComplaints'] ?? ''));
        $onExamination   = isset($body['onExamination']) ? trim((string)$body['onExamination']) : null;
        $advice          = isset($body['advice'])        ? trim((string)$body['advice'])        : null;
        $statusVal       = in_array($body['status'] ?? '', ['DRAFT', 'SUBMITTED'], true)
                           ? $body['status'] : 'DRAFT';
        $items           = $body['items'] ?? [];

        if ($patientName === '')     $errors['patientName']     = 'Patient name is required';
        if (!is_numeric($patientAge) || (int)$patientAge < 0)
                                     $errors['patientAge']      = 'Valid patient age is required';
        if ($patientGender === '')   $errors['patientGender']   = 'Patient gender is required';
        if ($chiefComplaints === '') $errors['chiefComplaints'] = 'Chief complaints are required';
        if (!is_array($items) || count($items) === 0)
                                     $errors['items']           = 'At least one prescription item is required';

        if ($items) {
            foreach ($items as $i => $item) {
                if (empty($item['medicineId'])) $errors["items.{$i}.medicineId"] = 'medicineId required';
                if (empty($item['dose']))        $errors["items.{$i}.dose"]        = 'dose required';
                if (empty($item['frequency']))   $errors["items.{$i}.frequency"]   = 'frequency required';
                if (empty($item['duration']))    $errors["items.{$i}.duration"]    = 'duration required';
            }
        }

        if ($errors) validationError($errors);

        // Verify all medicines exist and are active
        $medicineIds = array_unique(array_column($items, 'medicineId'));
        $ph          = implode(',', array_fill(0, count($medicineIds), '?'));
        $medStmt     = $pdo->prepare(
            "SELECT id FROM medicines WHERE id IN ({$ph}) AND is_active = 1"
        );
        $medStmt->execute($medicineIds);
        $foundMeds = $medStmt->fetchAll(\PDO::FETCH_COLUMN);

        if (count($foundMeds) !== count($medicineIds)) {
            badRequest('One or more medicines are not in the approved list or have been deactivated.');
        }

        // Insert prescription + items in a transaction
        $pdo->beginTransaction();
        try {
            $prescriptionId = cuid();
            $now = date('Y-m-d H:i:s');

            $pdo->prepare(
                "INSERT INTO prescriptions
                 (id, health_worker_id, patient_name, patient_age, patient_gender,
                  chief_complaints, on_examination, advice, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $prescriptionId,
                $user['id'],
                $patientName,
                (int) $patientAge,
                $patientGender,
                $chiefComplaints,
                $onExamination ?: null,
                $advice ?: null,
                $statusVal,
                $now,
                $now,
            ]);

            $itemInsert = $pdo->prepare(
                "INSERT INTO prescription_items
                 (id, prescription_id, medicine_id, dose, frequency, duration, instructions)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $createdItems = [];
            foreach ($items as $item) {
                $itemId = cuid();
                $itemInsert->execute([
                    $itemId,
                    $prescriptionId,
                    $item['medicineId'],
                    $item['dose'],
                    $item['frequency'],
                    $item['duration'],
                    isset($item['instructions']) ? (string)$item['instructions'] : null,
                ]);
                $createdItems[] = $itemId;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Fetch full prescription to return
        $stmt = $pdo->prepare(
            "SELECT p.id,
                    p.patient_name      AS patientName,
                    p.patient_age       AS patientAge,
                    p.patient_gender    AS patientGender,
                    p.chief_complaints  AS chiefComplaints,
                    p.on_examination    AS onExamination,
                    p.advice,
                    p.status,
                    p.reviewed_at       AS reviewedAt,
                    p.review_notes      AS reviewNotes,
                    p.created_at        AS createdAt,
                    p.updated_at        AS updatedAt,
                    hw.id               AS hwId,
                    hw.name             AS hwName,
                    rv.id               AS reviewerId,
                    rv.name             AS reviewerName
             FROM prescriptions p
             JOIN users hw ON p.health_worker_id = hw.id
             LEFT JOIN users rv ON p.reviewed_by_id = rv.id
             WHERE p.id = ?"
        );
        $stmt->execute([$prescriptionId]);
        $row = $stmt->fetch();

        $ph = implode(',', array_fill(0, count($createdItems), '?'));
        $itemStmt = $pdo->prepare(
            "SELECT pi.id, pi.dose, pi.frequency, pi.duration, pi.instructions,
                    m.id AS medicineId, m.name AS medicineName, m.form AS medicineForm
             FROM prescription_items pi
             JOIN medicines m ON pi.medicine_id = m.id
             WHERE pi.id IN ({$ph})"
        );
        $itemStmt->execute($createdItems);
        $itemRows = array_map(fn($i) => [
            'id'           => $i['id'],
            'dose'         => $i['dose'],
            'frequency'    => $i['frequency'],
            'duration'     => $i['duration'],
            'instructions' => $i['instructions'],
            'medicine'     => ['id' => $i['medicineId'], 'name' => $i['medicineName'], 'form' => $i['medicineForm']],
        ], $itemStmt->fetchAll());

        created(formatPrescriptionRow($row, $itemRows));
        return;
    }

    badRequest('Method not allowed');

} catch (PDOException $e) {
    error_log('[api/prescriptions] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/prescriptions] ' . $e->getMessage());
    serverError();
}
