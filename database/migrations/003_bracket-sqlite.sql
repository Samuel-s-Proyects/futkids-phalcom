CREATE TABLE IF NOT EXISTS tournament_matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    round_number INTEGER NOT NULL,
    match_number INTEGER NOT NULL,
    home_team_id INTEGER NULL,
    away_team_id INTEGER NULL,
    winner_team_id INTEGER NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','bye','completed')),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tournament_id, round_number, match_number),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE RESTRICT,
    FOREIGN KEY (home_team_id) REFERENCES teams(id) ON DELETE RESTRICT,
    FOREIGN KEY (away_team_id) REFERENCES teams(id) ON DELETE RESTRICT,
    FOREIGN KEY (winner_team_id) REFERENCES teams(id) ON DELETE RESTRICT,
    CHECK (home_team_id IS NULL OR away_team_id IS NULL OR home_team_id <> away_team_id)
);

CREATE INDEX idx_tournament_matches_round ON tournament_matches (tournament_id, round_number);
