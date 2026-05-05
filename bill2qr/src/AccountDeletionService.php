<?php

declare(strict_types=1);

final class AccountDeletionService
{
    private const REQUEST_TYPE_ACCOUNT_DELETE = 'account_delete';
    private const REQUEST_TYPE_ACTIVITY_DELETE = 'activity_delete';

    public function __construct(
        private readonly PDO $db,
        private readonly int $auditRetentionDays
    ) {
    }

    public function renderPublicPage(array $form = [], array $errors = [], ?array $success = null): array
    {
        $requestType = $this->normalizeRequestType($form['request_type'] ?? self::REQUEST_TYPE_ACCOUNT_DELETE, false)
            ?? self::REQUEST_TYPE_ACCOUNT_DELETE;
        $upiId = $this->escape((string) ($form['upi_id'] ?? ''));
        $recoveryPhone = $this->escape((string) ($form['recovery_phone'] ?? ''));
        $deviceUuid = $this->escape((string) ($form['device_uuid'] ?? ''));
        $notes = $this->escape((string) ($form['notes'] ?? ''));

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bill2QR Account Deletion</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f1e8;
            --panel: #fffdf8;
            --text: #1f2933;
            --muted: #52606d;
            --border: #d9cbb3;
            --accent: #0d6832;
            --accent-soft: #e7f5eb;
            --danger: #9b1c1c;
            --danger-soft: #fde8e8;
            --shadow: 0 18px 40px rgba(31, 41, 51, 0.08);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            background:
                radial-gradient(circle at top left, rgba(196, 166, 106, 0.2), transparent 28%),
                linear-gradient(180deg, #f8f3ea 0%, var(--bg) 100%);
            color: var(--text);
        }
        .shell {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 56px;
        }
        .hero, .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }
        .hero {
            padding: 28px;
            margin-bottom: 22px;
        }
        h1, h2, h3 {
            margin: 0 0 14px;
            line-height: 1.15;
        }
        h1 {
            font-size: clamp(2rem, 4vw, 3rem);
        }
        p, li, label, input, textarea, select, button {
            font-size: 1rem;
            line-height: 1.6;
        }
        .lead {
            color: var(--muted);
            max-width: 44rem;
        }
        .grid {
            display: grid;
            gap: 22px;
        }
        @media (min-width: 880px) {
            .grid {
                grid-template-columns: 1.15fr 0.85fr;
            }
        }
        .panel {
            padding: 24px;
        }
        .badge {
            display: inline-block;
            margin-bottom: 12px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #efe4cf;
            color: #6b4f1d;
            font-size: 0.9rem;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .callout,
        .success,
        .error-list {
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 18px;
        }
        .callout,
        .success {
            background: var(--accent-soft);
            border: 1px solid #b7e0c2;
        }
        .error-list {
            background: var(--danger-soft);
            border: 1px solid #f5b7b7;
            color: var(--danger);
        }
        .steps,
        .policy-list {
            margin: 0;
            padding-left: 18px;
        }
        .policy-card {
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
            background: #fffaf0;
            margin-bottom: 14px;
        }
        form {
            display: grid;
            gap: 14px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
        }
        input, textarea, select, button {
            width: 100%;
            border-radius: 12px;
            border: 1px solid #c8b79a;
            padding: 12px 14px;
            font: inherit;
            background: #fff;
            color: var(--text);
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        button {
            background: var(--accent);
            color: #fff;
            border: none;
            cursor: pointer;
            font-weight: bold;
        }
        button:hover {
            background: #0a5127;
        }
        .muted {
            color: var(--muted);
            font-size: 0.95rem;
        }
        .footer-note {
            margin-top: 18px;
            color: var(--muted);
            font-size: 0.92rem;
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <div class="badge">Google Play Data Deletion</div>
            <h1>Bill2QR by Actual Intelligence Solutions</h1>
            <p class="lead">
                Use this page to request account deletion or request deletion of some of your app data without deleting
                your account. This URL is provided for the Bill2QR Google Play listing.
            </p>
        </section>

        <div class="grid">
            <section class="panel">
                <h2>How to request deletion</h2>
                <ol class="steps">
                    <li>Choose whether you want full account deletion or only activity history deletion.</li>
                    <li>Enter the UPI ID and recovery phone number linked to your Bill2QR account.</li>
                    <li>Optionally add a device UUID and notes to help us match your records faster.</li>
                    <li>Submit the form. Your request is reviewed before permanent changes are made.</li>
                    <li>Approved full-account deletion removes active account access. Approved activity-only deletion keeps your account active.</li>
                </ol>

                <h2 style="margin-top: 26px;">What is deleted or kept</h2>

                <div class="policy-card">
                    <h3>Full account deletion</h3>
                    <ul class="policy-list">
                        <li>Deletes account and device records linked to the root owner account.</li>
                        <li>Deletes linked user-device records for that owner.</li>
                        <li>Deletes refresh tokens, device claim grants, and campaign/activity history.</li>
                        <li>Keeps only a minimal deletion-request audit record after completion.</li>
                    </ul>
                </div>

                <div class="policy-card">
                    <h3>Partial data deletion</h3>
                    <ul class="policy-list">
                        <li>Deletes campaign/activity history only.</li>
                        <li>Keeps the Bill2QR account, device records, and access credentials active.</li>
                    </ul>
                </div>

                <div class="policy-card">
                    <h3>Retention after full deletion</h3>
                    <ul class="policy-list">
                        <li>Minimal audit data is kept for <?= $this->auditRetentionDays ?> days for compliance and support review.</li>
                        <li>That audit record is limited to request type, status timestamps, masked or hashed identifiers, and operator notes if needed.</li>
                    </ul>
                </div>

                <p class="footer-note">
                    Support contact: <?= $this->escape((string) env('ACCOUNT_DELETION_SUPPORT_CONTACT', 'Use this form for deletion requests.')) ?>
                </p>
            </section>

            <section class="panel">
                <h2>Submit a deletion request</h2>

                <?php if ($success !== null): ?>
                    <div class="success">
                        <strong>Request submitted.</strong>
                        <div><?= $this->escape((string) ($success['message'] ?? '')) ?></div>
                        <div class="muted">Request ID: <?= (int) ($success['request_id'] ?? 0) ?></div>
                    </div>
                <?php else: ?>
                    <div class="callout">
                        Choose <strong>Full account deletion</strong> to remove the account and linked device data, or
                        choose <strong>Activity history deletion only</strong> to keep the account while deleting campaign/activity history.
                    </div>
                <?php endif; ?>

                <?php if ($errors !== []): ?>
                    <div class="error-list">
                        <strong>Please fix the following:</strong>
                        <ul class="policy-list">
                            <?php foreach ($errors as $error): ?>
                                <li><?= $this->escape($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= $this->escape($this->publicBasePath()) ?>/account-deletion/requests">
                    <div>
                        <label for="request_type">Request type</label>
                        <select id="request_type" name="request_type">
                            <option value="account_delete"<?= $requestType === self::REQUEST_TYPE_ACCOUNT_DELETE ? ' selected' : '' ?>>Full account deletion</option>
                            <option value="activity_delete"<?= $requestType === self::REQUEST_TYPE_ACTIVITY_DELETE ? ' selected' : '' ?>>Delete campaign/activity history only</option>
                        </select>
                    </div>

                    <div>
                        <label for="upi_id">UPI ID</label>
                        <input id="upi_id" name="upi_id" type="text" required value="<?= $upiId ?>" placeholder="merchant@okaxis">
                    </div>

                    <div>
                        <label for="recovery_phone">Recovery phone number</label>
                        <input id="recovery_phone" name="recovery_phone" type="text" required value="<?= $recoveryPhone ?>" placeholder="9876543210">
                    </div>

                    <div>
                        <label for="device_uuid">Device UUID (optional)</label>
                        <input id="device_uuid" name="device_uuid" type="text" value="<?= $deviceUuid ?>" placeholder="android-install-uuid">
                    </div>

                    <div>
                        <label for="notes">Notes (optional)</label>
                        <textarea id="notes" name="notes" placeholder="Add context that helps us identify your request."><?= $notes ?></textarea>
                    </div>

                    <button type="submit">Submit deletion request</button>
                </form>
            </section>
        </div>
    </main>
</body>
</html>
        <?php

        return [
            'status' => $success !== null ? 201 : 200,
            'html' => (string) ob_get_clean(),
        ];
    }

    public function createRequest(array $input): array
    {
        $requestType = $this->validateRequestType($input['request_type'] ?? null);
        $upiId = $this->validateUpiId($input['upi_id'] ?? null);
        $recoveryPhone = $this->validateRecoveryPhone($input['recovery_phone'] ?? null);
        $deviceUuid = $this->normalizeOptionalDeviceUuid($input['device_uuid'] ?? null);
        $notes = $this->normalizeNullableString($input['notes'] ?? null, 1000);

        $insert = $this->db->prepare(
            'INSERT INTO deletion_requests (
                request_type,
                upi_id,
                recovery_phone,
                device_uuid,
                notes,
                status,
                submitted_at,
                reviewed_at,
                completed_at,
                review_notes,
                upi_id_hash,
                recovery_phone_hash,
                device_uuid_hash
            ) VALUES (
                :request_type,
                :upi_id,
                :recovery_phone,
                :device_uuid,
                :notes,
                :status,
                UTC_TIMESTAMP(),
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL
            )'
        );
        $insert->execute([
            'request_type' => $requestType,
            'upi_id' => $upiId,
            'recovery_phone' => $recoveryPhone,
            'device_uuid' => $deviceUuid,
            'notes' => $notes,
            'status' => 'pending',
        ]);

        return [
            'status' => 201,
            'data' => [
                'message' => 'Your deletion request has been received and queued for review.',
                'request_id' => (int) $this->db->lastInsertId(),
                'request_type' => $requestType,
                'status' => 'pending',
            ],
        ];
    }

    public function renderAdminPage(?string $flashMessage = null): array
    {
        $requests = $this->listRequests();

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bill2QR Account Deletion Admin</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f5f7;
            color: #17212b;
        }
        main {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 18px 40px;
        }
        .panel {
            background: #fff;
            border: 1px solid #d9dee5;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(23, 33, 43, 0.06);
        }
        .flash {
            background: #e7f5eb;
            border: 1px solid #bfe2c7;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            vertical-align: top;
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.95rem;
        }
        th {
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #5b6570;
        }
        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .status-pending { background: #fff4d6; color: #8a5b00; }
        .status-approved { background: #dff3ff; color: #075985; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-completed { background: #dcfce7; color: #166534; }
        textarea, button {
            font: inherit;
        }
        textarea {
            width: 100%;
            min-height: 72px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            padding: 10px;
            margin-bottom: 8px;
        }
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        button {
            border: 0;
            border-radius: 8px;
            padding: 10px 12px;
            cursor: pointer;
            color: #fff;
        }
        .approve { background: #0369a1; }
        .complete { background: #166534; }
        .reject { background: #b91c1c; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    <main>
        <div class="panel">
            <h1>Bill2QR Account Deletion Admin</h1>
            <p class="muted">Protected review console for deletion requests submitted through the public Google Play deletion URL.</p>

            <?php if ($flashMessage !== null && trim($flashMessage) !== ''): ?>
                <div class="flash"><?= $this->escape($flashMessage) ?></div>
            <?php endif; ?>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Request</th>
                        <th>Identifiers</th>
                        <th>Notes</th>
                        <th>Status</th>
                        <th>Review</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td>#<?= (int) $request['id'] ?></td>
                        <td>
                            <strong><?= $this->escape((string) $request['request_type']) ?></strong><br>
                            <span class="muted">Submitted: <?= $this->escape((string) $request['submitted_at']) ?></span><br>
                            <span class="muted">Completed: <?= $this->escape((string) ($request['completed_at'] ?? '-')) ?></span>
                        </td>
                        <td>
                            <div><strong>UPI:</strong> <?= $this->escape((string) $request['upi_id']) ?></div>
                            <div><strong>Phone:</strong> <?= $this->escape((string) $request['recovery_phone']) ?></div>
                            <div><strong>Device:</strong> <?= $this->escape((string) ($request['device_uuid'] ?? '-')) ?></div>
                        </td>
                        <td>
                            <div><strong>User notes:</strong> <?= $this->escape((string) ($request['notes'] ?? '-')) ?></div>
                            <div><strong>Review notes:</strong> <?= $this->escape((string) ($request['review_notes'] ?? '-')) ?></div>
                        </td>
                        <td>
                            <span class="status status-<?= $this->escape((string) $request['status']) ?>"><?= $this->escape((string) $request['status']) ?></span><br>
                            <span class="muted">Reviewed: <?= $this->escape((string) ($request['reviewed_at'] ?? '-')) ?></span>
                        </td>
                        <td>
                            <?php if (in_array($request['status'], ['completed', 'rejected'], true)): ?>
                                <span class="muted">No actions available.</span>
                            <?php else: ?>
                                <form method="post" action="<?= $this->escape($this->publicBasePath()) ?>/account-deletion/admin/review">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <textarea name="review_notes" placeholder="Operator notes"><?= $this->escape((string) ($request['review_notes'] ?? '')) ?></textarea>
                                    <div class="actions">
                                        <?php if ($request['status'] === 'pending'): ?>
                                            <button class="approve" type="submit" name="action" value="approve">Approve</button>
                                        <?php endif; ?>
                                        <button class="complete" type="submit" name="action" value="complete">Complete</button>
                                        <button class="reject" type="submit" name="action" value="reject">Reject</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
        <?php

        return [
            'status' => 200,
            'html' => (string) ob_get_clean(),
        ];
    }

    public function reviewRequest(array $input): array
    {
        $requestId = $this->validatePositiveInt($input['request_id'] ?? null, 'request_id');
        $action = $this->validateReviewAction($input['action'] ?? null);
        $reviewNotes = $this->normalizeNullableString($input['review_notes'] ?? null, 1000);

        $this->db->beginTransaction();

        try {
            $request = $this->findRequestById($requestId, true);
            if ($request === null) {
                throw new InvalidArgumentException('Deletion request not found.', 404);
            }

            $currentStatus = (string) $request['status'];
            if ($action === 'approve') {
                if ($currentStatus === 'completed' || $currentStatus === 'rejected') {
                    throw new InvalidArgumentException('This request can no longer be approved.', 409);
                }

                $this->updateRequestStatus($requestId, 'approved', $reviewNotes, false);
                $this->db->commit();

                return [
                    'status' => 200,
                    'data' => [
                        'message' => 'Deletion request approved and ready for completion.',
                        'request_id' => $requestId,
                        'status' => 'approved',
                    ],
                ];
            }

            if ($action === 'reject') {
                if ($currentStatus === 'completed') {
                    throw new InvalidArgumentException('Completed requests cannot be rejected.', 409);
                }

                $this->updateRequestStatus($requestId, 'rejected', $reviewNotes, false);
                $this->db->commit();

                return [
                    'status' => 200,
                    'data' => [
                        'message' => 'Deletion request rejected.',
                        'request_id' => $requestId,
                        'status' => 'rejected',
                    ],
                ];
            }

            if ($currentStatus === 'rejected' || $currentStatus === 'completed') {
                throw new InvalidArgumentException('This request cannot be completed.', 409);
            }

            $outcome = $this->completeRequest($request, $reviewNotes);
            $this->db->commit();

            return [
                'status' => 200,
                'data' => [
                    'message' => $outcome,
                    'request_id' => $requestId,
                    'status' => 'completed',
                ],
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function isAdminAuthorized(?string $authorizationHeader): bool
    {
        $expectedToken = trim((string) env('ACCOUNT_DELETION_ADMIN_TOKEN', ''));
        if ($expectedToken === '') {
            return false;
        }

        if ($authorizationHeader !== null) {
            if (preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches) === 1) {
                return hash_equals($expectedToken, trim($matches[1]));
            }

            if (preg_match('/^Basic\s+(.+)$/i', $authorizationHeader, $matches) === 1) {
                $decoded = base64_decode(trim($matches[1]), true);
                if (is_string($decoded) && str_contains($decoded, ':')) {
                    [, $password] = explode(':', $decoded, 2);
                    return hash_equals($expectedToken, $password);
                }
            }
        }

        $basicPassword = $_SERVER['PHP_AUTH_PW'] ?? null;
        if (is_string($basicPassword) && $basicPassword !== '') {
            return hash_equals($expectedToken, $basicPassword);
        }

        return false;
    }

    private function listRequests(): array
    {
        $statement = $this->db->query(
            "SELECT *
             FROM deletion_requests
             ORDER BY FIELD(status, 'pending', 'approved', 'rejected', 'completed'), submitted_at DESC, id DESC"
        );

        return $statement->fetchAll() ?: [];
    }

    private function completeRequest(array $request, ?string $reviewNotes): string
    {
        $requestId = (int) $request['id'];
        $requestType = (string) $request['request_type'];
        $upiId = (string) $request['upi_id'];
        $recoveryPhone = (string) $request['recovery_phone'];

        $owner = $this->findOwnerRootByUpiAndPhone($upiId, $recoveryPhone, true);
        if ($owner === null) {
            $message = 'No active matching account was found at completion time. The request has been marked completed.';
            $this->finalizeRequestAudit($requestId, $request, $reviewNotes);
            return $message;
        }

        $devices = $this->findDeletionScopeDevices((int) $owner['id'], true);
        $deviceIds = array_map(static fn (array $device): int => (int) $device['id'], $devices);
        $deviceUuids = array_values(array_filter(array_map(
            static fn (array $device): string => (string) ($device['device_uuid'] ?? ''),
            $devices
        )));

        if ($requestType === self::REQUEST_TYPE_ACTIVITY_DELETE) {
            $deletedEvents = $this->deleteCampaignEventsByDeviceUuids($deviceUuids);
            $this->finalizeRequestAudit($requestId, $request, $reviewNotes);

            return sprintf(
                'Activity deletion completed. Removed %d campaign/activity event records for the matched account scope.',
                $deletedEvents
            );
        }

        $this->revokeRefreshTokensForDeviceIds($deviceIds);
        $this->deleteClaimGrantsForOwner((int) $owner['id']);
        $deletedEvents = $this->deleteCampaignEventsByDeviceUuids($deviceUuids);
        $deletedDevices = $this->deleteDevicesForOwner((int) $owner['id']);
        $this->finalizeRequestAudit($requestId, $request, $reviewNotes);

        return sprintf(
            'Account deletion completed. Removed %d device records and %d campaign/activity event records.',
            $deletedDevices,
            $deletedEvents
        );
    }

    private function findRequestById(int $requestId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM deletion_requests WHERE id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute(['id' => $requestId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function updateRequestStatus(int $requestId, string $status, ?string $reviewNotes, bool $markCompleted): void
    {
        $statement = $this->db->prepare(
            'UPDATE deletion_requests
             SET status = :status,
                 reviewed_at = UTC_TIMESTAMP(),
                 completed_at = CASE WHEN :mark_completed = 1 THEN UTC_TIMESTAMP() ELSE completed_at END,
                 review_notes = :review_notes
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'mark_completed' => $markCompleted ? 1 : 0,
            'review_notes' => $reviewNotes,
            'id' => $requestId,
        ]);
    }

    private function finalizeRequestAudit(int $requestId, array $request, ?string $reviewNotes): void
    {
        $statement = $this->db->prepare(
            'UPDATE deletion_requests
             SET status = :status,
                 reviewed_at = COALESCE(reviewed_at, UTC_TIMESTAMP()),
                 completed_at = UTC_TIMESTAMP(),
                 upi_id = :upi_id,
                 recovery_phone = :recovery_phone,
                 device_uuid = :device_uuid,
                 notes = NULL,
                 review_notes = :review_notes,
                 upi_id_hash = :upi_id_hash,
                 recovery_phone_hash = :recovery_phone_hash,
                 device_uuid_hash = :device_uuid_hash
             WHERE id = :id'
        );
        $statement->execute([
            'status' => 'completed',
            'upi_id' => $this->maskUpi((string) $request['upi_id']),
            'recovery_phone' => $this->maskPhone((string) $request['recovery_phone']),
            'device_uuid' => $request['device_uuid'] !== null && trim((string) $request['device_uuid']) !== ''
                ? $this->maskDeviceUuid((string) $request['device_uuid'])
                : null,
            'review_notes' => $reviewNotes,
            'upi_id_hash' => $this->hashIdentifier((string) $request['upi_id']),
            'recovery_phone_hash' => $this->hashIdentifier((string) $request['recovery_phone']),
            'device_uuid_hash' => $request['device_uuid'] !== null && trim((string) $request['device_uuid']) !== ''
                ? $this->hashIdentifier((string) $request['device_uuid'])
                : null,
            'id' => $requestId,
        ]);
    }

    private function findOwnerRootByUpiAndPhone(string $upiId, string $recoveryPhone, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM devices
                WHERE upi_id = :upi_id
                  AND recovery_phone = :recovery_phone
                  AND is_active = 1
                  AND device_role = :device_role
                  AND owner_device_id IS NULL
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'upi_id' => $upiId,
            'recovery_phone' => $recoveryPhone,
            'device_role' => 'owner',
        ]);
        $device = $statement->fetch();

        return is_array($device) ? $device : null;
    }

    private function findDeletionScopeDevices(int $ownerDeviceId, bool $forUpdate = false): array
    {
        $sql = 'SELECT *
                FROM devices
                WHERE id = :owner_device_id
                   OR owner_device_id = :owner_device_id';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute(['owner_device_id' => $ownerDeviceId]);

        return $statement->fetchAll() ?: [];
    }

    private function revokeRefreshTokensForDeviceIds(array $deviceIds): void
    {
        if ($deviceIds === []) {
            return;
        }

        $statement = $this->db->prepare(
            'UPDATE refresh_tokens
             SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()),
                 last_used_at = UTC_TIMESTAMP()
             WHERE device_id = ?'
        );

        foreach ($deviceIds as $deviceId) {
            $statement->execute([$deviceId]);
        }
    }

    private function deleteClaimGrantsForOwner(int $ownerDeviceId): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM device_claim_grants
             WHERE owner_device_id = :owner_device_id'
        );
        $statement->execute(['owner_device_id' => $ownerDeviceId]);
    }

    private function deleteCampaignEventsByDeviceUuids(array $deviceUuids): int
    {
        if ($deviceUuids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($deviceUuids), '?'));
        $statement = $this->db->prepare(
            sprintf('DELETE FROM campaign_events WHERE device_uuid IN (%s)', $placeholders)
        );
        $statement->execute($deviceUuids);

        return $statement->rowCount();
    }

    private function deleteDevicesForOwner(int $ownerDeviceId): int
    {
        $userDelete = $this->db->prepare(
            'DELETE FROM devices
             WHERE owner_device_id = :owner_device_id'
        );
        $userDelete->execute(['owner_device_id' => $ownerDeviceId]);
        $deletedUsers = $userDelete->rowCount();

        $ownerDelete = $this->db->prepare(
            'DELETE FROM devices
             WHERE id = :owner_device_id'
        );
        $ownerDelete->execute(['owner_device_id' => $ownerDeviceId]);

        return $deletedUsers + $ownerDelete->rowCount();
    }

    private function validateRequestType(mixed $value): string
    {
        $requestType = $this->normalizeRequestType($value, true);
        if ($requestType === null) {
            throw new InvalidArgumentException('request_type must be account_delete or activity_delete.', 422);
        }

        return $requestType;
    }

    private function normalizeRequestType(mixed $value, bool $required): ?string
    {
        if (!is_string($value)) {
            if ($required) {
                return null;
            }

            return null;
        }

        $normalized = trim($value);
        if ($normalized === self::REQUEST_TYPE_ACCOUNT_DELETE || $normalized === self::REQUEST_TYPE_ACTIVITY_DELETE) {
            return $normalized;
        }

        return null;
    }

    private function validateReviewAction(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('action is required.', 422);
        }

        $action = trim($value);
        if (!in_array($action, ['approve', 'reject', 'complete'], true)) {
            throw new InvalidArgumentException('action must be approve, reject, or complete.', 422);
        }

        return $action;
    }

    private function validatePositiveInt(mixed $value, string $field): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be a positive integer.', 422);
        }

        $number = (int) $value;
        if ($number <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer.', 422);
        }

        return $number;
    }

    private function validateUpiId(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('upi_id is required.', 422);
        }

        $upiId = strtolower(trim($value));
        if ($upiId === '' || strlen($upiId) > 100) {
            throw new InvalidArgumentException('upi_id must be between 1 and 100 characters.', 422);
        }

        if (!preg_match('/^[a-z0-9.\-_]{2,}@[a-z]{2,}$/', $upiId)) {
            throw new InvalidArgumentException('upi_id must be a valid UPI ID.', 422);
        }

        return $upiId;
    }

    private function validateRecoveryPhone(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException('recovery_phone is required.', 422);
        }

        $phone = preg_replace('/\D+/', '', (string) $value);
        if (!is_string($phone) || strlen($phone) < 10 || strlen($phone) > 15) {
            throw new InvalidArgumentException('recovery_phone must be a valid phone number.', 422);
        }

        return $phone;
    }

    private function normalizeOptionalDeviceUuid(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('device_uuid must be a string when provided.', 422);
        }

        $deviceUuid = trim($value);
        if ($deviceUuid === '') {
            return null;
        }

        if (strlen($deviceUuid) > 191) {
            throw new InvalidArgumentException('device_uuid must be between 1 and 191 characters.', 422);
        }

        return $deviceUuid;
    }

    private function normalizeNullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return substr($normalized, 0, $maxLength);
    }

    private function publicBasePath(): string
    {
        $basePath = parse_url((string) env('APP_URL', ''), PHP_URL_PATH);

        return is_string($basePath) ? rtrim($basePath, '/') : '';
    }

    private function hashIdentifier(string $value): string
    {
        return hash('sha256', $value);
    }

    private function maskUpi(string $upiId): string
    {
        $parts = explode('@', $upiId, 2);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';

        $prefix = substr($local, 0, min(2, strlen($local)));
        $maskedLocal = $prefix . str_repeat('*', max(3, strlen($local) - strlen($prefix)));

        return $domain === '' ? $maskedLocal : $maskedLocal . '@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        $visible = substr($phone, -2);
        return str_repeat('*', max(0, strlen($phone) - 2)) . $visible;
    }

    private function maskDeviceUuid(string $deviceUuid): string
    {
        if (strlen($deviceUuid) <= 8) {
            return substr($deviceUuid, 0, 2) . str_repeat('*', max(2, strlen($deviceUuid) - 2));
        }

        return substr($deviceUuid, 0, 4) . str_repeat('*', max(4, strlen($deviceUuid) - 8)) . substr($deviceUuid, -4);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
