<?php
// GET  /api/prescriptions/:id — get single prescription
// PATCH /api/prescriptions/:id — review (doctor) or edit (admin)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = trim($_GET['id'] ?? '');

if ($id === '') notFound('Prescription not found');

// Fetch full prescription row with joins (used for both GET and after PATCH)
function fetchPrescription(string $id): ?array
{
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT p.id,
                p.health_worker_id,
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
                hw.phone            AS hwPhone,
                rv.id               AS reviewerId,
                rv.name             AS reviewerName
         FROM prescriptions p
         JOIN users hw ON p.health_worker_id = hw.id
         LEFT JOIN users rv ON p.reviewed_by_id = rv.id
         WHERE p.id = ?"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function fetchPrescriptionItems(string $prescriptionId): array
{
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT pi.id, pi.dose, pi.frequency, pi.duration, pi.instructions,
                m.id           AS medicineId,
                m.name         AS medicineName,
                m.generic_name AS medicineGenericName,
                m.form         AS medicineForm
         FROM prescription_items pi
         JOIN medicines m ON pi.medicine_id = m.id
         WHERE pi.prescription_id = ?"
    );
    $stmt->execute([$prescriptionId]);
    return array_map(fn($i) => [
        'id'           => $i['id'],
        'dose'         => $i['dose'],
        'frequency'    => $i['frequency'],
        'duration'     => $i['duration'],
        'instructions' => $i['instructions'],
        'medicine'     => [
            'id'          => $i['medicineId'],
            'name'        => $i['medicineName'],
            'genericName' => $i['medicineGenericName'],
            'form'        => $i['medicineForm'],
        ],
    ], $stmt->fetchAll());
}

function formatFullPrescription(array $row, array $items = []): array
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
        'healthWorker'    => [
            'id'    => $row['hwId'],
            'name'  => $row['hwName'],
            'phone' => $row['hwPhone'],
        ],
        'reviewedBy'      => $row['reviewerId']
            ? ['id' => $row['reviewerId'], 'name' => $row['reviewerName']]
            : null,
        'items'           => $items,
    ];
}

try {

    // ── GET /api/prescriptions/:id ─────────────────────────────────────────────
    if ($method === 'GET') {
        $user = requireActiveUser();

        $row = fetchPrescription($id);
        if (!$row) notFound('Prescription not found');

        // Access control
        if ($user['role'] === 'HEALTH_WORKER' && $row['hwId'] !== $user['id']) {
            forbidden('You can only view your own prescriptions');
        }

        if ($user['role'] === 'DOCTOR') {
            $s = $pdo->prepare(
                "SELECT doctor_id FROM doctor_assignments WHERE health_worker_id = ? LIMIT 1"
            );
            $s->execute([$row['hwId']]);
            $assignment = $s->fetch();
            if (!$assignment || $assignment['doctor_id'] !== $user['id']) {
                forbidden('This prescription belongs to a health worker not assigned to you');
            }
        }

        ok(formatFullPrescription($row, fetchPrescriptionItems($id)));
        return;
    }

    // ── PATCH /api/prescriptions/:id ───────────────────────────────────────────
    if ($method === 'PATCH') {
        $user = requireActiveUser();

        $row = fetchPrescription($id);
        if (!$row) notFound('Prescription not found');

        $body = getJsonInput();
        if (empty($body)) badRequest('Request body is required');

        // ── Doctor: review a SUBMITTED prescription ────────────────────────────
        if ($user['role'] === 'DOCTOR') {
            if ($row['status'] !== 'SUBMITTED') {
                badRequest('Can only review SUBMITTED prescriptions');
            }

            $s = $pdo->prepare(
                "SELECT doctor_id FROM doctor_assignments WHERE health_worker_id = ? LIMIT 1"
            );
            $s->execute([$row['hwId']]);
            $assignment = $s->fetch();
            if (!$assignment || $assignment['doctor_id'] !== $user['id']) {
                forbidden('This health worker is not assigned to you');
            }

            $reviewNotes = isset($body['reviewNotes']) ? (string)$body['reviewNotes'] : null;
            $now         = date('Y-m-d H:i:s');

            $pdo->prepare(
                "UPDATE prescriptions
                 SET status = 'REVIEWED', reviewed_by_id = ?, reviewed_at = ?, review_notes = ?, updated_at = ?
                 WHERE id = ?"
            )->execute([$user['id'], $now, $reviewNotes, $now, $id]);

            $updated = fetchPrescription($id);
            ok(formatFullPrescription($updated, fetchPrescriptionItems($id)));
            return;
        }

        // ── Admin: edit any allowed field ──────────────────────────────────────
        if ($user['role'] === 'ADMIN') {
            $fieldMap = [
                'patientName'     => 'patient_name',
                'patientAge'      => 'patient_age',
                'patientGender'   => 'patient_gender',
                'chiefComplaints' => 'chief_complaints',
                'onExamination'   => 'on_examination',
                'advice'          => 'advice',
                'status'          => 'status',
                'reviewNotes'     => 'review_notes',
            ];

            $setClauses = [];
            $params     = [];
            foreach ($body as $key => $value) {
                if (isset($fieldMap[$key])) {
                    $setClauses[] = $fieldMap[$key] . ' = ?';
                    $params[]     = $value;
                }
            }

            if (empty($setClauses)) {
                badRequest('No valid fields provided to update');
            }

            $setClauses[] = 'updated_at = NOW()';
            $params[]     = $id;

            $pdo->prepare(
                "UPDATE prescriptions SET " . implode(', ', $setClauses) . " WHERE id = ?"
            )->execute($params);

            $updated = fetchPrescription($id);
            ok(formatFullPrescription($updated, fetchPrescriptionItems($id)));
            return;
        }

        forbidden('You cannot edit this prescription');
    }

    badRequest('Method not allowed');

} catch (PDOException $e) {
    error_log('[api/prescription-detail] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/prescription-detail] ' . $e->getMessage());
    serverError();
}
