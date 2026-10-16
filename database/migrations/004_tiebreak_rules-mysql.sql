ALTER TABLE tournaments ADD COLUMN extra_time_periods INTEGER NULL;
ALTER TABLE tournaments ADD COLUMN extra_time_period_minutes INTEGER NULL;
ALTER TABLE tournaments ADD COLUMN penalty_attempts_per_team INTEGER NULL;
ALTER TABLE tournaments ADD COLUMN penalty_sudden_death TINYINT NULL;
