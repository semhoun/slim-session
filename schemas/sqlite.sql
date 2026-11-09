CREATE TABLE slim_session_records (
    namespace VARCHAR(128) NOT NULL,
    id VARCHAR(128) NOT NULL,
    revision BIGINT NOT NULL,
    payload TEXT NOT NULL,
    expires_at DOUBLE PRECISION NOT NULL,
    PRIMARY KEY (namespace, id)
);
CREATE INDEX slim_session_records_expiration ON slim_session_records (namespace, expires_at);
CREATE TABLE slim_session_leases (
    namespace VARCHAR(128) NOT NULL,
    id VARCHAR(128) NOT NULL,
    owner VARCHAR(128) NOT NULL,
    expires_at DOUBLE PRECISION NOT NULL,
    PRIMARY KEY (namespace, id)
);
CREATE INDEX slim_session_leases_expiration ON slim_session_leases (namespace, expires_at);
CREATE TABLE slim_session_cache (
    namespace VARCHAR(128) NOT NULL,
    cache_key VARCHAR(64) NOT NULL,
    payload TEXT NOT NULL,
    expires_at DOUBLE PRECISION,
    PRIMARY KEY (namespace, cache_key)
);
CREATE INDEX slim_session_cache_expiration ON slim_session_cache (namespace, expires_at);
