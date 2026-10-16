ALTER TABLE users
    ADD COLUMN active TINYINT NOT NULL DEFAULT 1,
    ADD COLUMN must_change_password TINYINT NOT NULL DEFAULT 0,
    ADD COLUMN last_login_at DATETIME NULL,
    ADD COLUMN password_changed_at DATETIME NULL,
    ADD CONSTRAINT chk_user_flags CHECK (active IN (0,1) AND must_change_password IN (0,1));
