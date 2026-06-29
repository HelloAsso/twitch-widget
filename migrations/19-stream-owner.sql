-- Fix: les créateurs de streams existants doivent avoir is_owner = 1
UPDATE {prefix}user_right SET is_owner = 1 WHERE id_charity_stream IS NOT NULL;
