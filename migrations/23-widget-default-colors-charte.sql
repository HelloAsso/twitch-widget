-- Couleurs par défaut des nouveaux widgets aux couleurs de la charte HelloAsso
-- (vert #4A8100, noir #101010, gris clair #CDCED0, Lila #F1EDFF, Prune #572A37).
-- Remplace l'ancien violet #4C40CF et le bleu nuit #2E2F5E. Les widgets existants gardent leurs couleurs.
ALTER TABLE {prefix}widget_donation_goal_bar
    ALTER text_color_main SET DEFAULT '#101010',
    ALTER text_color_alt SET DEFAULT '#ffffff',
    ALTER bar_color SET DEFAULT '#4a8100',
    ALTER background_color SET DEFAULT '#ffffff';

ALTER TABLE {prefix}widget_card
    ALTER background_color SET DEFAULT '#ffffff',
    ALTER bar_color SET DEFAULT '#4a8100',
    ALTER bar_background_color SET DEFAULT '#cdced0',
    ALTER text_color SET DEFAULT '#101010',
    ALTER tag_color SET DEFAULT '#572a37',
    ALTER tag_background_color SET DEFAULT '#f1edff';
