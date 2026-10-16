ALTER TABLE tournament_matches
    ADD COLUMN home_score INTEGER NULL,
    ADD COLUMN away_score INTEGER NULL,
    ADD COLUMN decided_by VARCHAR(20) NULL,
    ADD COLUMN home_penalties INTEGER NULL,
    ADD COLUMN away_penalties INTEGER NULL,
    ADD COLUMN venue_key VARCHAR(150) NULL,
    ADD COLUMN leg INTEGER NULL,
    ADD COLUMN original_matchday_id INTEGER NULL,
    ADD COLUMN suspension_reason VARCHAR(255) NULL,
    ADD CONSTRAINT fk_tournament_match_original_matchday FOREIGN KEY (original_matchday_id) REFERENCES matchdays(id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_tournament_match_scores CHECK ((home_score IS NULL OR home_score >= 0) AND (away_score IS NULL OR away_score >= 0) AND (home_penalties IS NULL OR home_penalties >= 0) AND (away_penalties IS NULL OR away_penalties >= 0)),
    ADD CONSTRAINT chk_tournament_match_decided_by CHECK (decided_by IS NULL OR decided_by IN ('regulation','extra_time','penalties')),
    ADD CONSTRAINT chk_tournament_match_leg CHECK (leg IS NULL OR leg IN (1,2));

CREATE INDEX idx_tournament_matches_schedule ON tournament_matches (scheduled_at);
CREATE INDEX idx_tournament_matches_venue ON tournament_matches (venue_key, scheduled_at);
CREATE INDEX idx_tournament_matches_referee_schedule ON tournament_matches (referee_user_id, scheduled_at);
