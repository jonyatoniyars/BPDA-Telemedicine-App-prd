<?php
// PATCH  /api/admin/medicines/:id — update medicine (admin)
// DELETE /api/admin/medicines/:id — soft-delete / deactivate (admin)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = trim($_GET['id'] ?? '');

if ($id === '') notFound('Medicine not found');

function fetchMedicine(string $id): ?array
{
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT id, name, generic_name AS genericName, form,
                is_active AS isActive, created_at AS createdAt, updated_at AS updatedAt
         FROM medicines WHERE id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['isActive'] = (bool) $row['isActive'];
    return $row;
}

try {

    // ── PATCH /api/admin/medicines/:id ─────────────────────────────────────────
    if ($method === 'PATCH') {
        $user = requireAdmin();

        $medicine = fetchMedicine($id);
        if (!$medicine) notFound('Medicine not found');

        $body = getJsonInput();
        if (empty($body)) badRequest('Request body is required');

        $validForms = ['TABLET','CAPSULE','SYRUP','INJECTION','OINTMENT','DROPS','INHALER','SUPPOSITORY','PATCH','OTHER'];

        $setClauses = [];
        $params     = [];
        $auditData  = [];

        if (array_key_exists('name', $body)) {
            $val = trim((string)$body['name']);
            if ($val === '') validationError(['name' => 'Name cannot be empty']);
            $setClauses[] = 'name = ?';
            $params[]     = $val;
            $auditData['name'] = $val;
        }
        if (array_key_exists('genericName', $body)) {
            $val = $body['genericName'] !== null ? trim((string)$body['genericName']) : null;
            $setClauses[] = 'generic_name = ?';
            $params[]     = $val ?: null;
            $auditData['genericName'] = $val;
        }
        if (array_key_exists('form', $body)) {
            $val = (string)$body['form'];
            if (!in_array($val, $validForms, true)) validationError(['form' => 'Invalid medicine form']);
            $setClauses[] = 'form = ?';
            $params[]     = $val;
            $auditData['form'] = $val;
        }
        if (array_key_exists('isActive', $body)) {
            $val = (bool)$body['isActive'];
            $setClauses[] = 'is_active = ?';
            $params[]     = $val ? 1 : 0;
            $auditData['isActive'] = $val;
        }

        if (empty($setClauses)) badRequest('No valid fields provided to update');

        $setClauses[] = 'updated_at = NOW()';
        $params[]     = $id;

        $pdo->prepare(
            "UPDATE medicines SET " . implode(', ', $setClauses) . " WHERE id = ?"
        )->execute($params);

        logAudit($user['id'], 'MEDICINE_UPDATED', 'Medicine', $id, $auditData);

        ok(fetchMedicine($id));
        return;
    }

    // ── DELETE /api/admin/medicines/:id (soft delete = deactivate) ─────────────
    if ($method === 'DELETE') {
        $user = requireAdmin();

        $medicine = fetchMedicine($id);
        if (!$medicine) notFound('Medicine not found');

        $pdo->prepare(
            "UPDATE medicines SET is_active = 0, updated_at = NOW() WHERE id = ?"
        )->execute([$id]);

        logAudit($user['id'], 'MEDICINE_DEACTIVATED', 'Medicine', $id, ['name' => $medicine['name']]);

        $updated = fetchMedicine($id);
        ok(array_merge($updated, ['message' => 'Medicine deactivated']));
        return;
    }

    badRequest('Method not allowed');

} catch (PDOException $e) {
    error_log('[api/medicine-detail] DB: ' . $e->getMessage());
    serverError();
} catch (Throwable $e) {
    error_log('[api/medicine-detail] ' . $e->getMessage());
    serverError();
}
