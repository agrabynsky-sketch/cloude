-- Tables of the Bakuun adapter (development and tests). See schema.mysql.sql for comments.
CREATE TABLE IF NOT EXISTS bakuun_properties (
    bakuun_hotel_id      VARCHAR(120) NOT NULL PRIMARY KEY,
    ut_property_id       BIGINT       NOT NULL UNIQUE,
    ut_connection_id     VARCHAR(120) NULL,
    ut_connection_status VARCHAR(16)  NULL,
    status               VARCHAR(16)  NOT NULL,
    source               VARCHAR(16)  NOT NULL,
    hotel_name           VARCHAR(255) NULL,
    static_data          TEXT         NULL,
    created_at           DATETIME     NOT NULL,
    updated_at           DATETIME     NOT NULL
);
CREATE TABLE IF NOT EXISTS bakuun_mappings (
    bakuun_hotel_id VARCHAR(120) NOT NULL,
    entity_type     VARCHAR(16)  NOT NULL,
    bakuun_code     VARCHAR(120) NOT NULL,
    ut_id           BIGINT       NOT NULL,
    ut_room_id      BIGINT       NULL,
    name            VARCHAR(255) NULL,
    source          VARCHAR(16)  NOT NULL,
    updated_at      DATETIME     NOT NULL,
    PRIMARY KEY (bakuun_hotel_id, entity_type, bakuun_code)
);
CREATE INDEX IF NOT EXISTS idx_bakuun_mappings_ut ON bakuun_mappings (bakuun_hotel_id, entity_type, ut_id);
CREATE TABLE IF NOT EXISTS bakuun_restrictions (
    level       VARCHAR(8)  NOT NULL,
    ut_id       BIGINT      NOT NULL,
    field       VARCHAR(32) NOT NULL,
    stay_date   DATE        NOT NULL,
    value       INTEGER     NOT NULL,
    property_id BIGINT      NOT NULL,
    PRIMARY KEY (level, ut_id, field, stay_date)
);
CREATE INDEX IF NOT EXISTS idx_bakuun_restrictions_property ON bakuun_restrictions (property_id);
CREATE TABLE IF NOT EXISTS bakuun_bookings (
    reservation_id    VARCHAR(120) NOT NULL PRIMARY KEY,
    property_id       BIGINT       NOT NULL,
    status            VARCHAR(16)  NOT NULL,
    version           CHAR(36)     NOT NULL,
    modified_at       DATETIME     NULL,
    checkout          DATE         NOT NULL,
    payload           TEXT         NOT NULL,
    received_at       DATETIME     NOT NULL,
    state             VARCHAR(16)  NOT NULL,
    delivered_version CHAR(36)     NULL,
    delivered_status  VARCHAR(16)  NULL,
    bakuun_res_ids    TEXT         NULL,
    attempts          INTEGER      NOT NULL DEFAULT 0,
    next_attempt_at   DATETIME     NULL,
    last_error        TEXT         NULL,
    acked_version     CHAR(36)     NULL
);
CREATE INDEX IF NOT EXISTS idx_bakuun_bookings_state ON bakuun_bookings (state, next_attempt_at);
