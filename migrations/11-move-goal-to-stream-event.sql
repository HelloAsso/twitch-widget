-- Ajouter la colonne goal aux tables stream et event
ALTER TABLE {prefix}charity_stream ADD goal INT NOT NULL DEFAULT 1000;
ALTER TABLE {prefix}charity_event ADD goal INT NOT NULL DEFAULT 1000;

-- Copier les objectifs existants depuis les widgets barre de don
UPDATE {prefix}charity_stream cs
    INNER JOIN {prefix}widget_donation_goal_bar w ON w.charity_stream_guid = cs.guid
    SET cs.goal = w.goal
    WHERE w.goal IS NOT NULL AND w.goal > 0;

UPDATE {prefix}charity_event ce
    INNER JOIN {prefix}widget_donation_goal_bar w ON w.charity_event_guid = ce.guid
    SET ce.goal = w.goal
    WHERE w.goal IS NOT NULL AND w.goal > 0;

