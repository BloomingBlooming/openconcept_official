SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS openconcept_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    avatar_color CHAR(7) NOT NULL DEFAULT '#5E6AD2',
    avatar_kind VARCHAR(16) NOT NULL DEFAULT 'initials',
    avatar_value VARCHAR(255) NOT NULL DEFAULT '',
    ui_locale VARCHAR(35) NOT NULL DEFAULT 'ja-JP',
    role VARCHAR(40) NOT NULL DEFAULT 'editor',
    department VARCHAR(120) NOT NULL DEFAULT 'プロダクト',
    active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    invited_at TIMESTAMP(6) NULL,
    last_login_at TIMESTAMP(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    title VARCHAR(240) NOT NULL DEFAULT '無題',
    icon VARCHAR(32) NOT NULL DEFAULT '📄',
    cover VARCHAR(32) NOT NULL DEFAULT 'mint',
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    category VARCHAR(120) NOT NULL DEFAULT 'ナレッジ',
    tags_json JSON NOT NULL,
    manual_tags_json JSON NULL,
    blocks_json JSON NOT NULL,
    plain_text MEDIUMTEXT NOT NULL,
    visibility VARCHAR(32) NOT NULL DEFAULT 'company',
    access_department VARCHAR(120) NOT NULL DEFAULT '',
    comments_enabled TINYINT(1) NOT NULL DEFAULT 1,
    language_code VARCHAR(35) NOT NULL DEFAULT 'und',
    translation_group_id VARCHAR(64) NULL,
    source_page_id BIGINT UNSIGNED NULL,
    source_revision BIGINT UNSIGNED NULL,
    translation_status VARCHAR(32) NOT NULL DEFAULT 'original',
    translation_block_map_json JSON NULL,
    content_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    author_id BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    is_favorite TINYINT(1) NOT NULL DEFAULT 0,
    archived_at TIMESTAMP(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    published_at TIMESTAMP(6) NULL,
    CONSTRAINT fk_openconcept_pages_parent FOREIGN KEY (parent_id) REFERENCES openconcept_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_openconcept_pages_author FOREIGN KEY (author_id) REFERENCES openconcept_users(id),
    CONSTRAINT fk_openconcept_pages_editor FOREIGN KEY (updated_by) REFERENCES openconcept_users(id),
    CONSTRAINT fk_openconcept_pages_translation_source FOREIGN KEY (source_page_id) REFERENCES openconcept_pages(id) ON DELETE SET NULL,
    INDEX idx_pages_parent (parent_id),
    INDEX idx_pages_order (parent_id, sort_order),
    INDEX idx_pages_updated (updated_at),
    INDEX idx_pages_language (language_code),
    INDEX idx_pages_translation_source (source_page_id, language_code),
    INDEX idx_pages_translation_group (translation_group_id),
    FULLTEXT INDEX idx_pages_search (title, plain_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_page_access_members (
    page_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (page_id, user_id),
    CONSTRAINT fk_openconcept_page_access_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_page_access_user FOREIGN KEY (user_id) REFERENCES openconcept_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_page_access_granter FOREIGN KEY (granted_by) REFERENCES openconcept_users(id),
    INDEX idx_page_access_user (user_id, page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_page_access_departments (
    page_id BIGINT UNSIGNED NOT NULL,
    department VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    granted_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (page_id, department),
    CONSTRAINT fk_openconcept_page_access_department_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_page_access_department_granter FOREIGN KEY (granted_by) REFERENCES openconcept_users(id),
    INDEX idx_page_access_department (department, page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_page_public_shares (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    root_page_id BIGINT UNSIGNED NOT NULL,
    token CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_slug VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    publication_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    published_revision BIGINT UNSIGNED NULL,
    manifest_json LONGTEXT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    published_at TIMESTAMP(6) NULL,
    operation_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'idle',
    operation_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pending_manifest_json LONGTEXT NULL,
    operation_started_at TIMESTAMP(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_page_public_shares_root FOREIGN KEY (root_page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_page_public_shares_creator FOREIGN KEY (created_by) REFERENCES openconcept_users(id),
    CONSTRAINT fk_openconcept_page_public_shares_editor FOREIGN KEY (updated_by) REFERENCES openconcept_users(id),
    CONSTRAINT chk_openconcept_page_public_shares_token CHECK (CHAR_LENGTH(token) = 43),
    CONSTRAINT chk_openconcept_page_public_shares_revision CHECK (revision > 0),
    UNIQUE KEY uq_page_public_shares_root (root_page_id),
    UNIQUE KEY uq_page_public_shares_token (token),
    UNIQUE KEY uq_page_public_shares_slug (public_slug),
    INDEX idx_page_public_shares_enabled (enabled, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_page_public_share_pages (
    share_id BIGINT UNSIGNED NOT NULL,
    page_id BIGINT UNSIGNED NOT NULL,
    added_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (share_id, page_id),
    CONSTRAINT fk_openconcept_page_public_share_pages_share FOREIGN KEY (share_id) REFERENCES openconcept_page_public_shares(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_page_public_share_pages_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_page_public_share_pages_adder FOREIGN KEY (added_by) REFERENCES openconcept_users(id),
    INDEX idx_page_public_share_pages_page (page_id, share_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(240) NOT NULL,
    icon VARCHAR(32) NOT NULL,
    blocks_json JSON NOT NULL,
    meta_json JSON NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_revisions_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_revisions_user FOREIGN KEY (created_by) REFERENCES openconcept_users(id),
    INDEX idx_revisions_page (page_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    resolved_at TIMESTAMP(6) NULL,
    edited_at TIMESTAMP(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_comments_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_comments_parent FOREIGN KEY (parent_id) REFERENCES openconcept_comments(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_comments_user FOREIGN KEY (user_id) REFERENCES openconcept_users(id),
    INDEX idx_comments_page (page_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    message VARCHAR(500) NOT NULL,
    page_id BIGINT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_notifications_user FOREIGN KEY (user_id) REFERENCES openconcept_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_notifications_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(80) NOT NULL,
    subject_type VARCHAR(80) NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    detail_json JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_audit_user FOREIGN KEY (user_id) REFERENCES openconcept_users(id),
    INDEX idx_audit_subject (subject_type, subject_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_system_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_route_state (
    route_key VARCHAR(64) PRIMARY KEY,
    mode VARCHAR(32) NOT NULL,
    current_path VARCHAR(32) NOT NULL,
    future_generation VARCHAR(190) NULL,
    config_revision BIGINT UNSIGNED NOT NULL,
    rollback_readiness VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_route_audit (
    route_key VARCHAR(64) NOT NULL,
    config_revision BIGINT UNSIGNED NOT NULL,
    previous_revision BIGINT UNSIGNED NOT NULL,
    actor_kind VARCHAR(32) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    transition_kind VARCHAR(64) NOT NULL,
    previous_state_json LONGTEXT NULL,
    next_state_json LONGTEXT NOT NULL,
    metadata_json LONGTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (route_key, config_revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_index_engines (
    engine_instance_id VARCHAR(64) PRIMARY KEY,
    plugin_id VARCHAR(64) NOT NULL,
    configuration_json TEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'disabled',
    active_generation_id VARCHAR(68) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_index_generations (
    index_generation_id VARCHAR(68) PRIMARY KEY,
    engine_instance_id VARCHAR(64) NOT NULL,
    engine_plugin_id VARCHAR(64) NULL,
    engine_plugin_version VARCHAR(64) NULL,
    chunker_id VARCHAR(128) NULL,
    chunker_version VARCHAR(64) NULL,
    embedding_provider_id VARCHAR(128) NULL,
    embedding_model_id VARCHAR(190) NULL,
    embedding_model_revision VARCHAR(190) NULL,
    embedding_dimensions INT NULL,
    distance_metric VARCHAR(32) NULL,
    configuration_json LONGTEXT NOT NULL,
    source_snapshot_json LONGTEXT NOT NULL,
    metrics_json LONGTEXT NOT NULL,
    failure_code VARCHAR(120) NULL,
    status VARCHAR(32) NOT NULL,
    activated_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_rag_index_generations_engine_status (engine_instance_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_index_sync (
    engine_instance_id VARCHAR(64) NOT NULL,
    document_id VARCHAR(160) NOT NULL,
    revision_id VARCHAR(160) NOT NULL,
    content_hash CHAR(64) NOT NULL,
    indexed_at DATETIME(6) NULL,
    status VARCHAR(32) NOT NULL,
    error_code VARCHAR(120) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (engine_instance_id, document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_index_jobs (
    job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    engine_instance_id VARCHAR(64) NOT NULL,
    index_generation_id VARCHAR(68) NULL,
    job_type VARCHAR(32) NOT NULL,
    target_id VARCHAR(160) NOT NULL,
    revision_id VARCHAR(160) NOT NULL,
    content_hash CHAR(64) NOT NULL,
    snapshot_fingerprint CHAR(64) NOT NULL,
    indexed_items INT NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    next_retry_at DATETIME(6) NOT NULL,
    locked_at DATETIME(6) NULL,
    locked_by VARCHAR(120) NULL,
    error_code VARCHAR(120) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    UNIQUE KEY uq_rag_core_job_v3 (
        engine_instance_id,
        index_generation_id,
        job_type,
        target_id,
        revision_id,
        snapshot_fingerprint
    ),
    INDEX idx_rag_core_ready (status, next_retry_at, job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_index_runs (
    run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    engine_instance_id VARCHAR(64) NOT NULL,
    query_hash CHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    error_code VARCHAR(120) NULL,
    INDEX idx_rag_index_runs_engine_started (engine_instance_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value LONGTEXT NOT NULL,
    updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(96) NOT NULL UNIQUE,
    mime_type VARCHAR(160) NOT NULL,
    category VARCHAR(32) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    page_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_files_user FOREIGN KEY (uploaded_by) REFERENCES openconcept_users(id),
    CONSTRAINT fk_openconcept_files_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE SET NULL,
    INDEX idx_files_category (category, created_at),
    INDEX idx_files_page (page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_ai_conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(240) NOT NULL DEFAULT '新しいチャット',
    turn_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_page_id BIGINT UNSIGNED NULL,
    last_page_title VARCHAR(240) NOT NULL DEFAULT '',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    archived_at TIMESTAMP(6) NULL,
    CONSTRAINT fk_openconcept_ai_conversations_user FOREIGN KEY (user_id) REFERENCES openconcept_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_ai_conversations_page FOREIGN KEY (last_page_id) REFERENCES openconcept_pages(id) ON DELETE SET NULL,
    INDEX idx_ai_conversations_user (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_ai_chat_turns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    turn_index INT UNSIGNED NOT NULL,
    mode VARCHAR(24) NOT NULL DEFAULT 'search',
    question TEXT NOT NULL,
    answer MEDIUMTEXT NOT NULL,
    page_id BIGINT UNSIGNED NULL,
    page_title VARCHAR(240) NOT NULL DEFAULT '',
    sufficient TINYINT(1) NOT NULL DEFAULT 0,
    sources_json JSON NOT NULL,
    candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
    model VARCHAR(120) NOT NULL DEFAULT '',
    reasoning_effort VARCHAR(16) NOT NULL DEFAULT 'low',
    response_id VARCHAR(190) NOT NULL DEFAULT '',
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
    degraded TINYINT(1) NOT NULL DEFAULT 0,
    review_status VARCHAR(24) NOT NULL DEFAULT 'pending',
    review_note TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_ai_chat_turns_conversation FOREIGN KEY (conversation_id) REFERENCES openconcept_ai_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_ai_chat_turns_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_openconcept_ai_chat_turns_reviewer FOREIGN KEY (reviewed_by) REFERENCES openconcept_users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_ai_chat_turn (conversation_id, turn_index),
    INDEX idx_ai_chat_turns_conversation (conversation_id, turn_index),
    INDEX idx_ai_chat_turns_review (review_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_source_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    title VARCHAR(240) NOT NULL,
    category VARCHAR(120) NOT NULL,
    tags_json JSON NOT NULL,
    blocks_json JSON NOT NULL,
    plain_text MEDIUMTEXT NOT NULL,
    visibility VARCHAR(32) NOT NULL,
    access_department VARCHAR(120) NOT NULL DEFAULT '',
    author_id BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    source_created_at TIMESTAMP(6) NOT NULL,
    source_updated_at TIMESTAMP(6) NOT NULL,
    published_at TIMESTAMP(6) NULL,
    language_code VARCHAR(35) NOT NULL DEFAULT 'und',
    translation_group_id VARCHAR(64) NULL,
    source_page_id BIGINT UNSIGNED NULL,
    translation_role VARCHAR(16) NOT NULL DEFAULT 'original',
    translation_status VARCHAR(32) NOT NULL DEFAULT 'original',
    source_revision BIGINT UNSIGNED NULL,
    content_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    captured_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_rag_source_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_rag_source_author FOREIGN KEY (author_id) REFERENCES openconcept_users(id),
    CONSTRAINT fk_openconcept_rag_source_editor FOREIGN KEY (updated_by) REFERENCES openconcept_users(id),
    UNIQUE KEY uq_rag_source_version (page_id, source_hash),
    INDEX idx_rag_source_current (page_id, is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_source_units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_document_id BIGINT UNSIGNED NOT NULL,
    unit_index INT UNSIGNED NOT NULL,
    unit_key VARCHAR(190) NOT NULL,
    block_type VARCHAR(40) NOT NULL,
    heading_path_json JSON NOT NULL,
    content MEDIUMTEXT NOT NULL,
    content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    char_count INT UNSIGNED NOT NULL,
    CONSTRAINT fk_openconcept_rag_source_unit_document FOREIGN KEY (source_document_id) REFERENCES openconcept_rag_source_documents(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rag_source_unit_index (source_document_id, unit_index),
    UNIQUE KEY uq_rag_source_unit_key (source_document_id, unit_key),
    INDEX idx_rag_source_units_document (source_document_id, unit_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_source_access_members (
    source_document_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (source_document_id, user_id),
    CONSTRAINT fk_openconcept_rag_source_access_document FOREIGN KEY (source_document_id) REFERENCES openconcept_rag_source_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_rag_source_access_user FOREIGN KEY (user_id) REFERENCES openconcept_users(id) ON DELETE CASCADE,
    INDEX idx_rag_source_access_user (user_id, source_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_source_access_departments (
    source_document_id BIGINT UNSIGNED NOT NULL,
    department VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (source_document_id, department),
    CONSTRAINT fk_openconcept_rag_source_access_department_document FOREIGN KEY (source_document_id) REFERENCES openconcept_rag_source_documents(id) ON DELETE CASCADE,
    INDEX idx_rag_source_access_department (department, source_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    prompt_version VARCHAR(80) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'queued',
    reasoning_effort VARCHAR(16) NOT NULL DEFAULT 'medium',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    available_at TIMESTAMP(6) NOT NULL,
    locked_at TIMESTAMP(6) NULL,
    locked_by VARCHAR(190) NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    completed_at TIMESTAMP(6) NULL,
    CONSTRAINT fk_openconcept_rag_jobs_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rag_job_version (page_id, source_hash, prompt_version),
    INDEX idx_rag_jobs_ready (status, available_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_page_profiles (
    page_id BIGINT UNSIGNED PRIMARY KEY,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    language VARCHAR(40) NOT NULL,
    summary MEDIUMTEXT NOT NULL,
    short_summary TEXT NOT NULL,
    tags_json JSON NOT NULL,
    keywords_json JSON NOT NULL,
    entities_json JSON NOT NULL,
    questions_json JSON NOT NULL,
    model VARCHAR(120) NOT NULL,
    reasoning_effort VARCHAR(16) NOT NULL,
    prompt_version VARCHAR(80) NOT NULL,
    response_id VARCHAR(190) NOT NULL DEFAULT '',
    input_chars INT UNSIGNED NOT NULL DEFAULT 0,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
    analyzed_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_rag_profile_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_chunks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    prompt_version VARCHAR(80) NOT NULL,
    chunk_index INT UNSIGNED NOT NULL,
    chunk_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(240) NOT NULL,
    heading_path_json JSON NOT NULL,
    unit_ids_json JSON NOT NULL,
    content MEDIUMTEXT NOT NULL,
    chunk_summary TEXT NOT NULL,
    keywords_json JSON NOT NULL,
    search_text MEDIUMTEXT NOT NULL,
    token_estimate INT UNSIGNED NOT NULL DEFAULT 0,
    char_count INT UNSIGNED NOT NULL DEFAULT 0,
    model VARCHAR(120) NOT NULL,
    reasoning_effort VARCHAR(16) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_rag_chunks_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rag_chunk_version (page_id, source_hash, prompt_version, chunk_index),
    INDEX idx_rag_chunks_active (page_id, is_active, chunk_index),
    INDEX idx_rag_chunks_hash (source_hash),
    FULLTEXT INDEX idx_rag_chunks_search (title, chunk_summary, search_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_generated_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    prompt_version VARCHAR(80) NOT NULL,
    tag VARCHAR(120) NOT NULL,
    normalized_tag VARCHAR(120) NOT NULL,
    relevance DECIMAL(5,4) NOT NULL DEFAULT 0,
    model VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_rag_generated_tags_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rag_generated_tag_version (page_id, source_hash, prompt_version, normalized_tag),
    INDEX idx_rag_generated_tags_active (page_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_canonical_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    canonical_name VARCHAR(120) NOT NULL,
    normalized_name VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    tag_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'general',
    created_source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ai',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_rag_canonical_tag_name (normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_tag_aliases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_id BIGINT UNSIGNED NOT NULL,
    alias VARCHAR(120) NOT NULL,
    normalized_alias VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ai',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_rag_alias_tag FOREIGN KEY (tag_id) REFERENCES openconcept_rag_canonical_tags(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rag_tag_alias_name (normalized_alias),
    UNIQUE KEY uq_rag_tag_alias_pair (tag_id, normalized_alias),
    INDEX idx_rag_tag_aliases_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_page_tag_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    source_hash VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    prompt_version VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    tag_id BIGINT UNSIGNED NOT NULL,
    metadata_field VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confidence DECIMAL(5,4) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_rag_page_tag_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_rag_page_tag_tag FOREIGN KEY (tag_id) REFERENCES openconcept_rag_canonical_tags(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rag_page_tag_link (page_id, source_hash, prompt_version, tag_id, metadata_field, source),
    INDEX idx_rag_page_tag_links_page (page_id, source, is_active, metadata_field),
    INDEX idx_rag_page_tag_links_filter (metadata_field, tag_id, is_active, page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_source_unit_blocks (
    source_unit_id BIGINT UNSIGNED PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    block_id VARCHAR(190) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_rag_unit_block_unit FOREIGN KEY (source_unit_id) REFERENCES openconcept_rag_source_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_rag_unit_block_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    INDEX idx_rag_source_unit_blocks_page (page_id, block_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_block_metadata (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    prompt_version VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    block_id VARCHAR(190) NOT NULL,
    block_type VARCHAR(40) NOT NULL,
    summary TEXT NOT NULL,
    keywords_json JSON NOT NULL,
    entities_json JSON NOT NULL,
    model VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_openconcept_rag_block_metadata_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rag_block_metadata_version (page_id, source_hash, prompt_version, block_id),
    INDEX idx_rag_block_metadata_active (page_id, is_active, block_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_rag_chunk_blocks (
    chunk_id BIGINT UNSIGNED NOT NULL,
    page_id BIGINT UNSIGNED NOT NULL,
    block_id VARCHAR(190) NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (chunk_id, block_id),
    CONSTRAINT fk_openconcept_rag_chunk_block_chunk FOREIGN KEY (chunk_id) REFERENCES openconcept_rag_chunks(id) ON DELETE CASCADE,
    CONSTRAINT fk_openconcept_rag_chunk_block_page FOREIGN KEY (page_id) REFERENCES openconcept_pages(id) ON DELETE CASCADE,
    INDEX idx_rag_chunk_blocks_page (page_id, block_id, chunk_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE openconcept_rag_source_documents
    MODIFY COLUMN source_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 4;

-- Upgrade existing installations without overwriting pages that already have
-- a normalized multi-department ACL. New installations have no rows here.
INSERT IGNORE INTO openconcept_page_access_departments (page_id, department, granted_by)
SELECT p.id, TRIM(p.access_department), p.updated_by
FROM openconcept_pages p
LEFT JOIN openconcept_page_access_departments existing ON existing.page_id = p.id
WHERE existing.page_id IS NULL
  AND TRIM(p.access_department) <> '';

INSERT IGNORE INTO openconcept_rag_source_access_departments (source_document_id, department)
SELECT d.id, TRIM(d.access_department)
FROM openconcept_rag_source_documents d
LEFT JOIN openconcept_rag_source_access_departments existing ON existing.source_document_id = d.id
WHERE existing.source_document_id IS NULL
  AND TRIM(d.access_department) <> '';

-- Generation 6: Core-owned common plugin API data.
CREATE TABLE IF NOT EXISTS openconcept_extension_records (
    scope VARCHAR(64) NOT NULL,
    collection VARCHAR(64) NOT NULL,
    record_key VARCHAR(190) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    revision BIGINT NOT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    PRIMARY KEY (scope, collection, record_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_extension_jobs (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    job_name VARCHAR(64) NOT NULL,
    dedupe_key VARCHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    state VARCHAR(32) NOT NULL,
    attempts BIGINT NOT NULL,
    available_at BIGINT NOT NULL,
    lease_token VARCHAR(64) NULL,
    lease_until BIGINT NULL,
    last_error LONGTEXT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    UNIQUE (scope, dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_extension_events (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    event_name VARCHAR(64) NOT NULL,
    source_key VARCHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    created_at BIGINT NOT NULL,
    UNIQUE (event_name, source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS openconcept_extension_search_content (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    source_type VARCHAR(64) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    source_version VARCHAR(128) NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    page_id BIGINT NULL,
    body_text LONGTEXT NOT NULL,
    provenance_json LONGTEXT NOT NULL,
    state VARCHAR(32) NOT NULL,
    revision BIGINT NOT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
