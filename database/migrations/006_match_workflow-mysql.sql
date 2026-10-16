ALTER TABLE tournament_matches
    ADD COLUMN venue VARCHAR(150) NULL,
    ADD COLUMN matchday_id INTEGER NULL,
    ADD COLUMN referee_user_id INTEGER NULL,
    ADD COLUMN started_at DATETIME NULL,
    ADD COLUMN confirmed_at DATETIME NULL,
    ADD COLUMN confirmed_by_user_id INTEGER NULL,
    ADD COLUMN result_revision INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN workflow_status VARCHAR(30) NOT NULL DEFAULT 'unprogrammed',
    ADD CONSTRAINT fk_tournament_match_matchday FOREIGN KEY (matchday_id) REFERENCES matchdays(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_tournament_match_referee FOREIGN KEY (referee_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_tournament_match_confirmed_by FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_tournament_match_workflow CHECK (workflow_status IN ('unprogrammed','scheduled','in_progress','awaiting_confirmation','confirmed','suspended','cancelled','bye'));

UPDATE tournament_matches
SET workflow_status = CASE
    WHEN status = 'bye' THEN 'bye'
    WHEN status = 'completed' THEN 'confirmed'
    WHEN scheduled_at IS NOT NULL THEN 'scheduled'
    ELSE 'unprogrammed'
END;

ALTER TABLE incidents
    ADD COLUMN tournament_match_id INTEGER NULL,
    ADD COLUMN team_id INTEGER NULL,
    ADD COLUMN minute INTEGER NULL,
    ADD CONSTRAINT fk_incident_tournament_match FOREIGN KEY (tournament_match_id) REFERENCES tournament_matches(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_incident_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_incident_match_minute CHECK (minute IS NULL OR minute BETWEEN 0 AND 150);

CREATE TABLE IF NOT EXISTS tournament_match_goal_events (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    tournament_match_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    minute INTEGER NOT NULL,
    own_goal TINYINT NOT NULL DEFAULT 0,
    created_by_user_id INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_match_id) REFERENCES tournament_matches(id) ON DELETE RESTRICT,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE RESTRICT,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (minute BETWEEN 0 AND 150 AND own_goal IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tournament_match_result_audit (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    tournament_match_id INTEGER NOT NULL,
    actor_user_id INTEGER NOT NULL,
    action VARCHAR(30) NOT NULL,
    revision INTEGER NOT NULL DEFAULT 0,
    snapshot JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_match_id) REFERENCES tournament_matches(id) ON DELETE RESTRICT,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_tournament_matches_referee ON tournament_matches (referee_user_id, workflow_status);
CREATE INDEX idx_match_goal_events_match ON tournament_match_goal_events (tournament_match_id, minute);
CREATE INDEX idx_match_incidents_match ON incidents (tournament_match_id, minute);
CREATE INDEX idx_match_result_audit_match ON tournament_match_result_audit (tournament_match_id, id);
