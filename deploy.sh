#!/usr/bin/env bash
# Deployment script — run on the server after pulling the latest code.
#
# Usage: bash deploy.sh
#
# Safe to run repeatedly. Every step below is idempotent: migrations only
# apply what is pending, and each seeder either adopts what already exists or
# leaves it untouched. Nothing here overwrites a decision made in Settings.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT/backend"

echo "==> Installing/updating Composer dependencies..."
composer install --no-dev --optimize-autoloader

echo "==> Caching config, routes and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Running database migrations..."
php artisan migrate --force

echo "==> Seeding global settings (module toggles)..."
# firstOrCreate: establishes a default when a key is absent, never rewrites a
# stored value. Module entitlements are set per client through Settings.
php artisan db:seed --class=GlobalSettingSeeder --force

echo "==> Seeding roles and permissions..."
# Every permission is firstOrCreate'd, so this adds what a release introduced
# (journal_entries, finance_reports, finance_settings, void_*) and leaves
# existing role assignments alone.
php artisan db:seed --class=RolesAndPermissionsSeeder --force

# ── Finance chart of accounts ────────────────────────────────────────────────
# Both seeders are guarded and safe on an existing database:
#
#   FinanceChartOfAccountsSeeder  skips entirely once any account category
#                                 exists, so a client's hand-made chart is
#                                 never touched.
#   FinanceGlAccountsSeeder       per account: adopts one that already exists,
#                                 creates it only if missing, and never
#                                 overwrites a mapping someone changed.
#
# Run unconditionally: on an installation without Finance they create a chart
# nobody opens, which costs nothing and means enabling the module later needs
# no extra deployment step.
echo "==> Seeding Finance chart of accounts..."
php artisan db:seed --class="Modules\\Finance\\Database\\Seeders\\FinanceChartOfAccountsSeeder" --force

echo "==> Mapping Finance posting roles to accounts..."
php artisan db:seed --class="Modules\\Finance\\Database\\Seeders\\FinanceGlAccountsSeeder" --force

echo "==> Restarting queue workers..."
php artisan queue:restart

# ── Frontend ─────────────────────────────────────────────────────────────────
# Skipped when npm is unavailable, for servers where the bundle is built in CI
# and copied in. A stale bundle is the most common cause of "the new page
# redirects to login" — the route exists in the code but not in what the
# browser loaded.
if [ -d "$ROOT/frontend" ] && command -v npm >/dev/null 2>&1; then
  echo "==> Building frontend..."
  cd "$ROOT/frontend"
  npm ci
  npm run build
  cd "$ROOT/backend"
else
  echo "==> Skipping frontend build (npm not found or frontend/ absent)."
  echo "    Build it separately, or the browser keeps serving the old bundle."
fi

echo
echo "==> Deployment complete."
echo
echo "    Finance readiness is NOT automatic. Two things still need a human:"
echo "      1. Settings      -> enable the Finance module for this client"
echo "      2. Ledger Accounts -> create ONE account per real bank account"
echo "         (no payment or receipt can post without it)"
echo
echo "    Then open Finance > Account Mapping: the banner there states"
echo "    whether anything is still unmapped, before a document fails."
