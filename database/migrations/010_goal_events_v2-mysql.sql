ALTER TABLE tournament_match_goal_events
    ADD COLUMN period_number INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN credited_team_id INTEGER NULL,
    ADD COLUMN updated_at DATETIME NULL,
    ADD CONSTRAINT fk_goal_event_credited_team FOREIGN KEY (credited_team_id) REFERENCES teams(id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_goal_event_period CHECK (period_number >= 0);
