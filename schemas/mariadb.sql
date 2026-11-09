CREATE TABLE slim_session_records (
    namespace VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    expires_at DOUBLE NOT NULL,
    PRIMARY KEY (namespace, id),
    INDEX slim_session_records_expiration (namespace, expires_at)
) ENGINE=InnoDB;
CREATE TABLE slim_session_leases (
    namespace VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DOUBLE NOT NULL,
    PRIMARY KEY (namespace, id),
    INDEX slim_session_leases_expiration (namespace, expires_at)
) ENGINE=InnoDB;
CREATE TABLE slim_session_cache (
    namespace VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cache_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    expires_at DOUBLE,
    PRIMARY KEY (namespace, cache_key),
    INDEX slim_session_cache_expiration (namespace, expires_at)
) ENGINE=InnoDB;
