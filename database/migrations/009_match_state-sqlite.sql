ALTER TABLE tournament_matches ADD COLUMN home_score INTEGER NULL CHECK (home_score IS NULL OR home_score >= 0);
ALTER TABLE tournament_matches ADD COLUMN away_score INTEGER NULL CHECK (away_score IS NULL OR away_score >= 0);
ALTER TABLE tournament_matches ADD COLUMN decided_by VARCHAR(20) NULL CHECK (decided_by IS NULL OR decided_by IN ('regulation','extra_time','penalties'));
ALTER TABLE tournament_matches ADD COLUMN home_penalties INTEGER NULL CHECK (home_penalties IS NULL OR home_penalties >= 0);
ALTER TABLE tournament_matches ADD COLUMN away_penalties INTEGER NULL CHECK (away_penalties IS NULL OR away_penalties >= 0);
ALTER TABLE tournament_matches ADD COLUMN venue_key VARCHAR(150) NULL;
ALTER TABLE tournament_matches ADD COLUMN leg INTEGER NULL CHECK (leg IS NULL OR leg IN (1,2));
ALTER TABLE tournament_matches ADD COLUMN original_matchday_id INTEGER NULL REFERENCES matchdays(id) ON DELETE RESTRICT;
ALTER TABLE tournament_matches ADD COLUMN suspension_reason VARCHAR(255) NULL;

CREATE INDEX idx_tournament_matches_schedule ON tournament_matches (scheduled_at);
CREATE INDEX idx_tournament_matches_venue ON tournament_matches (venue_key, scheduled_at);
CREATE INDEX idx_tournament_matches_referee_schedule ON tournament_matches (referee_user_id, scheduled_at);
