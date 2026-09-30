#!/bin/sh
# Builds repol-deploy.zip: exactly what goes into public_html (no tests, secrets or dev tooling).
set -e
cd "$(dirname "$0")/.."
rm -f repol-deploy.zip
zip -rq repol-deploy.zip index.html privacy.html data-deletion.html favicon.svg .htaccess \
  styles.css studio.css dark.css theme.css fonts.css legal.css \
  app.js studio.js live.js router.js motion.js assets \
  api/index.php api/cron.php api/.htaccess api/schema.sql api/config.sample.php api/src api/templates
echo "repol-deploy.zip ready ($(du -h repol-deploy.zip | cut -f1))"
