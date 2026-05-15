ALTER TABLE {prefix}widget_alert_box ADD cache_updated_at DATETIME(6) NULL AFTER cache_data;
ALTER TABLE {prefix}widget_donation_goal_bar ADD cache_updated_at DATETIME(6) NULL AFTER cache_data;
ALTER TABLE {prefix}widget_card ADD cache_updated_at DATETIME(6) NULL AFTER cache_data;

