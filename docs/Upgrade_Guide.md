# Upgrade & Version Migration Guide

## Platform Version Upgrades

### Step 1: Backup Current Platform State
Generate a pre-upgrade encrypted system backup:
```bash
php artisan backup:create
```

### Step 2: Update Web App Control Plane (VPS)
```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Step 3: Update Worker Engine (Mac Mini)
```bash
cd worker-engine
git pull origin main
source venv/bin/activate
pip install -r requirements.txt --upgrade
# Restart python main.py
```
