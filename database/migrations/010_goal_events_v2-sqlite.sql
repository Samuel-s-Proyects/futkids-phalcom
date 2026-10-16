ALTER TABLE tournament_match_goal_events ADD COLUMN period_number INTEGER NOT NULL DEFAULT 0 CHECK (period_number >= 0);
ALTER TABLE tournament_match_goal_events ADD COLUMN credited_team_id INTEGER NULL REFERENCES teams(id) ON DELETE RESTRICT;
ALTER TABLE tournament_match_goal_events ADD COLUMN updated_at DATETIME NULL;
