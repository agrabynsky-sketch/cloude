-- Booking queue of the Exely adapter (development and tests).
CREATE TABLE IF NOT EXISTS exely_bookings (
    reservation_id    VARCHAR(120) NOT NULL PRIMARY KEY,
    property_id       BIGINT       NOT NULL,
    status            VARCHAR(16)  NOT NULL,
    version           CHAR(36)     NOT NULL,
    modified_at       DATETIME     NULL,
    checkout          DATE         NOT NULL,
    payload           TEXT         NOT NULL,
    received_at       DATETIME     NOT NULL,
    confirmed_version CHAR(36)     NULL,
    exely_number      VARCHAR(120) NULL,
    exely_status      VARCHAR(16)  NULL,
    confirmed_at      DATETIME     NULL,
    rejected_by_exely INTEGER      NOT NULL DEFAULT 0,
    acked_version     CHAR(36)     NULL
);
CREATE INDEX IF NOT EXISTS idx_exely_bookings_property ON exely_bookings (property_id);
CREATE INDEX IF NOT EXISTS idx_exely_bookings_checkout ON exely_bookings (checkout);
