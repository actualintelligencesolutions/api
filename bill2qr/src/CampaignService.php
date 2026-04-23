<?php

declare(strict_types=1);

final class CampaignService
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function resolveActiveCampaign(array $input): array
    {
        $deviceUuid = $this->validateDeviceUuid($input['device_uuid'] ?? null);
        $placement = $this->validatePlacement($input['placement'] ?? 'app_open');
        $deviceContext = $this->findDeviceContext($deviceUuid);
        $campaign = $this->findFirstEligibleCampaign($deviceUuid, $placement, $deviceContext['audience_role']);

        if ($campaign === null) {
            return [
                'status' => 200,
                'data' => [
                    'has_campaign' => false,
                    'placement' => $placement,
                ],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'has_campaign' => true,
                'placement' => $placement,
                'campaign' => $this->serializeCampaign($campaign, $deviceContext, $deviceUuid),
            ],
        ];
    }

    public function renderHostedHtml(string $campaignKey, ?string $deviceUuid = null): array
    {
        $campaignKey = $this->validateCampaignKey($campaignKey);
        $campaign = $this->findCampaignByKey($campaignKey);

        if ($campaign === null || !$this->isCampaignActive($campaign)) {
            throw new InvalidArgumentException('Hosted campaign not found.', 404);
        }

        if (($campaign['render_mode'] ?? '') !== 'hosted_html') {
            throw new InvalidArgumentException('Campaign is not configured for hosted HTML rendering.', 422);
        }

        $htmlBody = (string) ($campaign['html_body'] ?? '');
        if (trim($htmlBody) === '') {
            throw new RuntimeException('Hosted campaign HTML is empty.', 500);
        }

        if ($deviceUuid !== null && trim($deviceUuid) !== '') {
            $context = $this->findDeviceContext($this->validateDeviceUuid($deviceUuid));
            if (!$this->audienceMatches((string) $campaign['audience_role'], $context['audience_role'])) {
                throw new InvalidArgumentException('Campaign is not available for this device audience.', 403);
            }
        }

        return [
            'status' => 200,
            'html' => $htmlBody,
        ];
    }

    public function trackEvent(array $input): array
    {
        $campaignKey = $this->validateCampaignKey($input['campaign_key'] ?? null);
        $deviceUuid = $this->validateDeviceUuid($input['device_uuid'] ?? null);
        $eventType = $this->validateEventType($input['event_type'] ?? null);
        $ctaId = $this->normalizeNullableString($input['cta_id'] ?? null, 50);
        $dwellTimeMs = $this->validateNullableInt($input['dwell_time_ms'] ?? null, 'dwell_time_ms');
        $metadataJson = $this->encodeMetadata($input['metadata'] ?? null);

        $campaign = $this->findCampaignByKey($campaignKey);
        if ($campaign === null) {
            throw new InvalidArgumentException('Campaign not found.', 404);
        }

        $insert = $this->db->prepare(
            'INSERT INTO campaign_events (
                campaign_id,
                device_uuid,
                event_type,
                cta_id,
                dwell_time_ms,
                metadata_json,
                occurred_at,
                created_at
            ) VALUES (
                :campaign_id,
                :device_uuid,
                :event_type,
                :cta_id,
                :dwell_time_ms,
                :metadata_json,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )'
        );
        $insert->execute([
            'campaign_id' => $campaign['id'],
            'device_uuid' => $deviceUuid,
            'event_type' => $eventType,
            'cta_id' => $ctaId,
            'dwell_time_ms' => $dwellTimeMs,
            'metadata_json' => $metadataJson,
        ]);

        return [
            'status' => 200,
            'data' => [
                'message' => 'Campaign event tracked.',
            ],
        ];
    }

    private function findFirstEligibleCampaign(string $deviceUuid, string $placement, string $audienceRole): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM campaigns
             WHERE status = :status
               AND placement = :placement
               AND start_at <= UTC_TIMESTAMP()
               AND (end_at IS NULL OR end_at >= UTC_TIMESTAMP())
             ORDER BY priority DESC, id DESC'
        );
        $statement->execute([
            'status' => 'active',
            'placement' => $placement,
        ]);

        while (($campaign = $statement->fetch()) !== false) {
            if (!is_array($campaign)) {
                continue;
            }

            if (!$this->audienceMatches((string) $campaign['audience_role'], $audienceRole)) {
                continue;
            }

            if (($campaign['render_mode'] ?? '') === 'hosted_html' && trim((string) ($campaign['html_body'] ?? '')) === '') {
                continue;
            }

            if ($this->isPastFrequencyCap((int) $campaign['id'], $deviceUuid, $campaign['max_impressions_per_device'])) {
                continue;
            }

            if ($this->isWithinCooldown((int) $campaign['id'], $deviceUuid, $campaign['cooldown_minutes'])) {
                continue;
            }

            return $campaign;
        }

        return null;
    }

    private function findCampaignByKey(string $campaignKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM campaigns
             WHERE campaign_key = :campaign_key
             LIMIT 1'
        );
        $statement->execute(['campaign_key' => $campaignKey]);
        $campaign = $statement->fetch();

        return is_array($campaign) ? $campaign : null;
    }

    private function findDeviceContext(string $deviceUuid): array
    {
        $statement = $this->db->prepare(
            'SELECT device_role
             FROM devices
             WHERE device_uuid = :device_uuid
               AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['device_uuid' => $deviceUuid]);
        $device = $statement->fetch();

        if (!is_array($device)) {
            return [
                'device_known' => false,
                'device_role' => null,
                'audience_role' => 'unknown',
            ];
        }

        $role = ($device['device_role'] ?? 'owner') === 'user' ? 'user' : 'owner';

        return [
            'device_known' => true,
            'device_role' => $role,
            'audience_role' => $role,
        ];
    }

    private function serializeCampaign(array $campaign, array $deviceContext, string $deviceUuid): array
    {
        $appUrl = rtrim((string) env('APP_URL', ''), '/');
        $campaignKey = (string) $campaign['campaign_key'];
        $htmlUrl = null;

        if (($campaign['render_mode'] ?? '') === 'hosted_html') {
            $query = http_build_query([
                'campaign_key' => $campaignKey,
                'device_uuid' => $deviceUuid,
            ]);
            $htmlUrl = $appUrl !== ''
                ? $appUrl . '/campaigns/html?' . $query
                : '/campaigns/html?' . $query;
        }

        return [
            'campaign_key' => $campaignKey,
            'name' => $campaign['name'],
            'campaign_type' => $campaign['campaign_type'],
            'placement' => $campaign['placement'],
            'render_mode' => $campaign['render_mode'],
            'screen_type' => $campaign['screen_type'],
            'is_dismissible' => (bool) $campaign['is_dismissible'],
            'priority' => (int) $campaign['priority'],
            'device_context' => [
                'device_known' => $deviceContext['device_known'],
                'device_role' => $deviceContext['device_role'],
            ],
            'content' => [
                'title' => $campaign['title'],
                'subtitle' => $campaign['subtitle'],
                'body' => $campaign['body_text'],
                'primary_cta' => $this->serializeCta(
                    $campaign['primary_cta_label'],
                    $campaign['primary_cta_url'],
                    'primary'
                ),
                'secondary_cta' => $this->serializeCta(
                    $campaign['secondary_cta_label'],
                    $campaign['secondary_cta_url'],
                    'secondary'
                ),
                'theme' => $this->decodeJsonObject($campaign['theme_json']),
                'payload' => $this->decodeJsonObject($campaign['payload_json']),
                'html_url' => $htmlUrl,
            ],
            'tracking' => [
                'campaign_key' => $campaignKey,
                'track_endpoint' => '/campaigns/events',
            ],
        ];
    }

    private function serializeCta(mixed $label, mixed $url, string $ctaId): ?array
    {
        if (!is_string($label) || trim($label) === '') {
            return null;
        }

        return [
            'cta_id' => $ctaId,
            'label' => trim($label),
            'action_url' => is_string($url) && trim($url) !== '' ? trim($url) : null,
        ];
    }

    private function isCampaignActive(array $campaign): bool
    {
        if (($campaign['status'] ?? '') !== 'active') {
            return false;
        }

        $now = time();
        $startAt = strtotime((string) $campaign['start_at']);
        $endAt = $campaign['end_at'] !== null ? strtotime((string) $campaign['end_at']) : null;

        if ($startAt === false || $startAt > $now) {
            return false;
        }

        return $endAt === null || $endAt >= $now;
    }

    private function audienceMatches(string $campaignAudience, string $deviceAudience): bool
    {
        return $campaignAudience === 'all' || $campaignAudience === $deviceAudience;
    }

    private function isPastFrequencyCap(int $campaignId, string $deviceUuid, mixed $maxImpressions): bool
    {
        if ($maxImpressions === null) {
            return false;
        }

        $limit = (int) $maxImpressions;
        if ($limit <= 0) {
            return false;
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS impression_count
             FROM campaign_events
             WHERE campaign_id = :campaign_id
               AND device_uuid = :device_uuid
               AND event_type = :event_type'
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'device_uuid' => $deviceUuid,
            'event_type' => 'impression',
        ]);

        return (int) $statement->fetchColumn() >= $limit;
    }

    private function isWithinCooldown(int $campaignId, string $deviceUuid, mixed $cooldownMinutes): bool
    {
        if ($cooldownMinutes === null) {
            return false;
        }

        $cooldown = (int) $cooldownMinutes;
        if ($cooldown <= 0) {
            return false;
        }

        $statement = $this->db->prepare(
            'SELECT occurred_at
             FROM campaign_events
             WHERE campaign_id = :campaign_id
               AND device_uuid = :device_uuid
             ORDER BY occurred_at DESC, id DESC
             LIMIT 1'
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'device_uuid' => $deviceUuid,
        ]);

        $lastOccurredAt = $statement->fetchColumn();
        if (!is_string($lastOccurredAt) || trim($lastOccurredAt) === '') {
            return false;
        }

        $lastSeenAt = strtotime($lastOccurredAt);
        if ($lastSeenAt === false) {
            return false;
        }

        return $lastSeenAt + ($cooldown * 60) > time();
    }

    private function decodeJsonObject(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function encodeMetadata(mixed $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        if (!is_array($metadata)) {
            throw new InvalidArgumentException('metadata must be an object.', 422);
        }

        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Unable to encode event metadata.', 500);
        }

        return $encoded;
    }

    private function validateDeviceUuid(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('device_uuid is required.', 422);
        }

        $deviceUuid = trim($value);
        if ($deviceUuid === '' || strlen($deviceUuid) > 191) {
            throw new InvalidArgumentException('device_uuid must be between 1 and 191 characters.', 422);
        }

        return $deviceUuid;
    }

    private function validatePlacement(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('placement is required.', 422);
        }

        $placement = trim($value);
        if ($placement === '' || strlen($placement) > 50) {
            throw new InvalidArgumentException('placement must be between 1 and 50 characters.', 422);
        }

        return $placement;
    }

    private function validateCampaignKey(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('campaign_key is required.', 422);
        }

        $campaignKey = trim($value);
        if ($campaignKey === '' || strlen($campaignKey) > 100 || !preg_match('/^[a-zA-Z0-9._-]+$/', $campaignKey)) {
            throw new InvalidArgumentException('campaign_key must be a safe identifier up to 100 characters.', 422);
        }

        return $campaignKey;
    }

    private function validateEventType(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('event_type is required.', 422);
        }

        $eventType = trim($value);
        $allowed = ['impression', 'click', 'dismiss', 'close'];
        if (!in_array($eventType, $allowed, true)) {
            throw new InvalidArgumentException('event_type must be one of: impression, click, dismiss, close.', 422);
        }

        return $eventType;
    }

    private function validateNullableInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_int($value) && !is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be an integer.', 422);
        }

        $normalized = (int) $value;
        if ($normalized < 0) {
            throw new InvalidArgumentException($field . ' must be zero or greater.', 422);
        }

        return $normalized;
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
}
