ALTER TABLE tournament_matches ADD COLUMN venue VARCHAR(150) NULL;
ALTER TABLE tournament_matches ADD COLUMN matchday_id INTEGER NULL REFERENCES matchdays(id) ON DELETE RESTRICT;
ALTER TABLE tournament_matches ADD COLUMN referee_user_id INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT;
ALTER TABLE tournament_matches ADD COLUMN started_at DATETIME NULL;
ALTER TABLE tournament_matches ADD COLUMN confirmed_at DATETIME NULL;
ALTER TABLE tournament_matches ADD COLUMN confirmed_by_user_id INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT;
ALTER TABLE tournament_matches ADD COLUMN result_revision INTEGER NOT NULL DEFAULT 0;
ALTER TABLE tournament_matches ADD COLUMN workflow_status VARCHAR(30) NOT NULL DEFAULT 'unprogrammed' CHECK (workflow_status IN ('unprogrammed','scheduled','in_progress','awaiting_confirmation','confirmed','suspended','cancelled','bye'));

UPDATE tournament_matches
SET workflow_status = CASE
    WHEN status = 'bye' THEN 'bye'
    WHEN status = 'completed' THEN 'confirmed'
    WHEN scheduled_at IS NOT NULL THEN 'scheduled'
    ELSE 'unprogrammed'
END;

ALTER TABLE incidents ADD COLUMN tournament_match_id INTEGER NULL REFERENCES tournament_matches(id) ON DELETE RESTRICT;
ALTER TABLE incidents ADD COLUMN team_id INTEGER NULL REFERENCES teams(id) ON DELETE RESTRICT;
ALTER TABLE incidents ADD COLUMN minute INTEGER NULL CHECK (minute IS NULL OR minute BETWEEN 0 AND 150);

CREATE TABLE IF NOT EXISTS tournament_match_goal_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_match_id INTEGER NOT NULL REFERENCES tournament_matches(id) ON DELETE RESTRICT,
    player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE RESTRICT,
    team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE RESTRICT,
    minute INTEGER NOT NULL CHECK (minute BETWEEN 0 AND 150),
    own_goal INTEGER NOT NULL DEFAULT 0 CHECK (own_goal IN (0,1)),
    created_by_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tournament_match_result_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_match_id INTEGER NOT NULL REFERENCES tournament_matches(id) ON DELETE RESTRICT,
    actor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    action VARCHAR(30) NOT NULL,
    revision INTEGER NOT NULL DEFAULT 0,
    snapshot TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_tournament_matches_referee ON tournament_matches (referee_user_id, workflow_status);
CREATE INDEX idx_match_goal_events_match ON tournament_match_goal_events (tournament_match_id, minute);
CREATE INDEX idx_match_incidents_match ON incidents (tournament_match_id, minute);
CREATE INDEX idx_match_result_audit_match ON tournament_match_result_audit (tournament_match_id, id);
