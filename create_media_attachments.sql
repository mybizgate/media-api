-- MyBizGate shared attachment schema: run in a CONSUMING API database,
-- e.g. client-api, registration-api, booking-api, not media-api.
-- Alternative to Laravel migration in attachment_integration.md.
CREATE TABLE media_attachments (
    id CHAR(26) NOT NULL,
    tenant_id VARCHAR(64) NOT NULL,
    entity_type VARCHAR(48) NOT NULL,
    entity_id VARCHAR(64) NOT NULL,
    media_id CHAR(26) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'attachment',
    slot VARCHAR(40) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ma_entity_media_role_unique
        (tenant_id, entity_type, entity_id, media_id, role),
    UNIQUE KEY ma_one_per_slot
        (tenant_id, entity_type, entity_id, slot),
    KEY ma_lookup (tenant_id, entity_type, entity_id, role, sort_order),
    KEY ma_media_lookup (tenant_id, media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
