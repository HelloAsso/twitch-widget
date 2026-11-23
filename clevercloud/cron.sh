#!/bin/bash -l

# Les crons tournent sur chaque instance : on n'exécute que sur la première
if [[ "$INSTANCE_NUMBER" != "0" ]]; then
  exit 0
fi

cd ${APP_HOME}
php cron.php
