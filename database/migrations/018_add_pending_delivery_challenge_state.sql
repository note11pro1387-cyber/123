ALTER TABLE verification_challenges
    MODIFY COLUMN state ENUM('pending_delivery','active','verified_reset','used','revoked','exhausted') NOT NULL DEFAULT 'active';
