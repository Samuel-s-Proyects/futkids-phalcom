CREATE TABLE IF NOT EXISTS tournaments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(150) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','registration','in_progress','finished')),
    draw_method VARCHAR(10) NOT NULL DEFAULT 'random' CHECK (draw_method IN ('random','manual')),
    registrations_closed_at DATETIME NULL,
    bracket_generated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (start_date <= end_date)
);

CREATE TABLE IF NOT EXISTS tournament_teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    seed_order INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tournament_id, team_id),
    UNIQUE (tournament_id, seed_order),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE RESTRICT,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE RESTRICT
);

CREATE INDEX idx_tournaments_status ON tournaments (status);
CREATE INDEX idx_tournament_teams_team_id ON tournament_teams (team_id);
