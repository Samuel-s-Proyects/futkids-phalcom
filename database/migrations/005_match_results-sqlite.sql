ALTER TABLE tournament_matches ADD COLUMN scheduled_at DATETIME NULL;
ALTER TABLE tournament_matches ADD COLUMN first_kicker_team_id INTEGER NULL REFERENCES teams(id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS tournament_match_period_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_match_id INTEGER NOT NULL,
    period_number INTEGER NOT NULL,
    home_score INTEGER NOT NULL,
    away_score INTEGER NOT NULL,
    home_own_goals INTEGER NOT NULL DEFAULT 0,
    away_own_goals INTEGER NOT NULL DEFAULT 0,
    UNIQUE (tournament_match_id, period_number),
    FOREIGN KEY (tournament_match_id) REFERENCES tournament_matches(id) ON DELETE RESTRICT,
    CHECK (period_number >= 0 AND home_score >= 0 AND away_score >= 0 AND home_own_goals >= 0 AND away_own_goals >= 0)
);

CREATE TABLE IF NOT EXISTS tournament_match_goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_match_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    goals INTEGER NOT NULL,
    UNIQUE (tournament_match_id, player_id),
    FOREIGN KEY (tournament_match_id) REFERENCES tournament_matches(id) ON DELETE RESTRICT,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE RESTRICT,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE RESTRICT,
    CHECK (goals > 0)
);

CREATE TABLE IF NOT EXISTS tournament_match_penalties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_match_id INTEGER NOT NULL,
    kick_number INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    scored INTEGER NOT NULL,
    UNIQUE (tournament_match_id, kick_number),
    FOREIGN KEY (tournament_match_id) REFERENCES tournament_matches(id) ON DELETE RESTRICT,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE RESTRICT,
    CHECK (kick_number > 0 AND scored IN (0,1))
);

CREATE INDEX idx_tournament_match_goals_player ON tournament_match_goals (player_id);
