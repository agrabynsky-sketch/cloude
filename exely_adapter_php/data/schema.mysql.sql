-- Booking queue of the Exely adapter (MySQL 5.5.3+ for utf8mb4).
CREATE TABLE IF NOT EXISTS exely_bookings (
    reservation_id    VARCHAR(120) NOT NULL,
    property_id       BIGINT       NOT NULL,
    status            VARCHAR(16)  NOT NULL,
    -- echoToken of the current version, handed to Exely
    version           CHAR(36)     NOT NULL,
    modified_at       DATETIME     NULL,
    checkout          DATE         NOT NULL,
    -- the Unit.Travel reservation JSON, card data stripped
    payload           MEDIUMTEXT   NOT NULL,
    received_at       DATETIME     NOT NULL,
    -- version Exely last confirmed, and what it answered
    confirmed_version CHAR(36)     NULL,
    exely_number      VARCHAR(120) NULL,
    exely_status      VARCHAR(16)  NULL,
    confirmed_at      DATETIME     NULL,
    rejected_by_exely TINYINT(1)   NOT NULL DEFAULT 0,
    -- version last acknowledged to Unit.Travel
    acked_version     CHAR(36)     NULL,
    PRIMARY KEY (reservation_id),
    KEY idx_exely_bookings_property (property_id),
    KEY idx_exely_bookings_checkout (checkout)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
