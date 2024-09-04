ALTER TABLE {prefix}widget_donation_goal_bar CHANGE text_color text_color_main CHAR(7) NOT NULL DEFAULT('#2e2f5e');
ALTER TABLE {prefix}widget_donation_goal_bar ADD text_color_alt CHAR(7) NOT NULL DEFAULT('#ffffff') AFTER text_color_main;
ALTER TABLE {prefix}widget_donation_goal_bar MODIFY bar_color CHAR(7) NOT NULL DEFAULT('#4c40cf');
ALTER TABLE {prefix}widget_donation_goal_bar MODIFY background_color CHAR(7) NOT NULL DEFAULT('#ffffff');
ALTER TABLE {prefix}widget_donation_goal_bar MODIFY text_content varchar(255) NOT NULL DEFAULT('');
ALTER TABLE {prefix}widget_donation_goal_bar MODIFY goal INT NOT NULL DEFAULT(1000);