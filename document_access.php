<?php
/*
|--------------------------------------------------------------------------
| PestZone - acces documente pentru admin si angajati teren
|--------------------------------------------------------------------------
| Adminul poate accesa orice document.
| Angajatul poate accesa doar procesele verbale legate de programările
| tehnicianului lui.
|--------------------------------------------------------------------------
*/

if (!function_exists('pzdoc_user_can_access_appointment_for_pv')) {
    function pzdoc_user_can_access_appointment_for_pv(PDO $pdo, int $appointmentId, bool $requireFinalizedForTeam = false): bool
    {
        if ($appointmentId <= 0) {
            return false;
        }

        if (function_exists('is_admin') && is_admin()) {
            return true;
        }

        if (!function_exists('is_team_user') || !is_team_user()) {
            return false;
        }

        $teamId = function_exists('current_team_id') ? current_team_id() : null;
        if (!$teamId) {
            return false;
        }

        try {
            $sql = "SELECT COUNT(*) AS total FROM appointments WHERE id = ? AND team_member_id = ?";
            $params = [$appointmentId, (int)$teamId];
            if ($requireFinalizedForTeam) {
                $sql .= " AND status = 'finalizata'";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['total'] ?? 0) > 0;
        } catch (Throwable $e) {
            error_log('PestZone document access appointment error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('pzdoc_user_can_access_document')) {
    function pzdoc_user_can_access_document(PDO $pdo, array $document): bool
    {
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }

        if (!function_exists('is_team_user') || !is_team_user()) {
            return false;
        }

        $type = function_exists('pzdoc_normalize_document_type')
            ? pzdoc_normalize_document_type((string)($document['document_type'] ?? ''))
            : (string)($document['document_type'] ?? '');

        if ($type !== 'proces_verbal') {
            return false;
        }

        $appointmentId = (int)($document['appointment_id'] ?? 0);
        if ($appointmentId <= 0) {
            $payload = [];
            if (function_exists('pzdoc_json_decode')) {
                $payload = pzdoc_json_decode($document['payload_json'] ?? null);
            }
            $appointmentId = (int)($payload['appointment_id'] ?? 0);
        }

        return pzdoc_user_can_access_appointment_for_pv($pdo, $appointmentId, false);
    }
}

if (!function_exists('pzdoc_load_accessible_document')) {
    function pzdoc_load_accessible_document(PDO $pdo, int $documentId, bool $withChildren = true): ?array
    {
        if ($documentId <= 0 || !function_exists('pzdoc_get_document')) {
            return null;
        }

        $document = pzdoc_get_document($pdo, $documentId, $withChildren);
        if (!$document) {
            return null;
        }

        return pzdoc_user_can_access_document($pdo, $document) ? $document : null;
    }
}
