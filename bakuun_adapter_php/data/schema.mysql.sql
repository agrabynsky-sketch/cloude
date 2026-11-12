-- Tables of the Bakuun adapter (MySQL 5.5.3+ for utf8mb4).

-- A Bakuun property linked to a Unit.Travel property (onboarding state).
CREATE TABLE IF NOT EXISTS bakuun_properties (
    bakuun_hotel_id      VARCHAR(120) NOT NULL,
    ut_property_id       BIGINT       NOT NULL,
    ut_connection_id     VARCHAR(120) NULL,
    ut_connection_status VARCHAR(16)  NULL,
    -- pending, active, suspended, disconnected
    status               VARCHAR(16)  NOT NULL,
    -- bakuun: linked by us with Bakuun's codes; partner: Bakuun maps our codes (getRoom)
    source               VARCHAR(16)  NOT NULL,
    hotel_name           VARCHAR(255) NULL,
    -- Bakuun GetPropertyInfo propertyData (JSON)
    static_data          MEDIUMTEXT   NULL,
    created_at           DATETIME     NOT NULL,
    updated_at           DATETIME     NOT NULL,
    PRIMARY KEY (bakuun_hotel_id),
    UNIQUE KEY uq_bakuun_properties_ut (ut_property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bakuun room / rate plan code → Unit.Travel room_id / rate_plan_id.
CREATE TABLE IF NOT EXISTS bakuun_mappings (
    bakuun_hotel_id VARCHAR(120) NOT NULL,
    -- room or rate_plan
    entity_type     VARCHAR(16)  NOT NULL,
    bakuun_code     VARCHAR(120) NOT NULL,
    ut_id           BIGINT       NOT NULL,
    -- for rate plans: the Unit.Travel room the rate plan belongs to
    ut_room_id      BIGINT       NULL,
    name            VARCHAR(255) NULL,
    -- auto, manual or partner
    source          VARCHAR(16)  NOT NULL,
    updated_at      DATETIME     NOT NULL,
    PRIMARY KEY (bakuun_hotel_id, entity_type, bakuun_code),
    KEY idx_bakuun_mappings_ut (bakuun_hotel_id, entity_type, ut_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bakuun's room-level and rate-plan-level restrictions per date, combined before they go to Unit.Travel.
CREATE TABLE IF NOT EXISTS bakuun_restrictions (
    -- room or rate
    level       VARCHAR(8)  NOT NULL,
    ut_id       BIGINT      NOT NULL,
    field       VARCHAR(32) NOT NULL,
    stay_date   DATE        NOT NULL,
    value       INT         NOT NULL,
    property_id BIGINT      NOT NULL,
    PRIMARY KEY (level, ut_id, field, stay_date),
    KEY idx_bakuun_restrictions_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Unit.Travel reservations and their delivery to Bakuun.
CREATE TABLE IF NOT EXISTS bakuun_bookings (
    reservation_id    VARCHAR(120) NOT NULL,
    property_id       BIGINT       NOT NULL,
    status            VARCHAR(16)  NOT NULL,
    version           CHAR(36)     NOT NULL,
    modified_at       DATETIME     NULL,
    checkout          DATE         NOT NULL,
    -- the Unit.Travel reservation JSON, card data stripped
    payload           MEDIUMTEXT   NOT NULL,
    received_at       DATETIME     NOT NULL,
    -- pending, delivered, rejected, retry
    state             VARCHAR(16)  NOT NULL,
    delivered_version CHAR(36)     NULL,
    delivered_status  VARCHAR(16)  NULL,
    -- JSON list of Bakuun reservation ids (one per room)
    bakuun_res_ids    TEXT         NULL,
    attempts          INT          NOT NULL DEFAULT 0,
    next_attempt_at   DATETIME     NULL,
    last_error        TEXT         NULL,
    acked_version     CHAR(36)     NULL,
    PRIMARY KEY (reservation_id),
    KEY idx_bakuun_bookings_state (state, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
