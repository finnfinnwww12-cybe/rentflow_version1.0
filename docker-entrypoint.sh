#!/bin/sh
set -e

# Ensure Laravel storage and bootstrap cache directories are writable
echo "Setting permissions..."
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# If PORT environment variable is set (dynamic port mapping for Railway/Heroku), update Nginx config
if [ -n "$PORT" ]; then
    echo "Configuring Nginx to listen on dynamic port $PORT..."
    sed -i "s/listen 80;/listen $PORT;/g" /etc/nginx/http.d/default.conf
    sed -i "s/listen \[::\]:80;/listen \[::\]:$PORT;/g" /etc/nginx/http.d/default.conf
fi

# Print database variables for debugging
echo "Database Connection Diagnostics:"
echo "  DB_CONNECTION: '$DB_CONNECTION'"
echo "  DB_HOST: '$DB_HOST'"
echo "  DB_PORT: '$DB_PORT'"
echo "  DB_DATABASE: '$DB_DATABASE'"
echo "  DB_USERNAME: '$DB_USERNAME'"
echo "  DB_URL: '$DB_URL'"
echo "  DATABASE_URL: '$DATABASE_URL'"

# Check if APP_KEY is set, if not, generate it or warn the user
if [ -z "$APP_KEY" ]; then
    echo "WARNING: APP_KEY environment variable is not set. Generating one..."
    php artisan key:generate --show
fi

# Run database migrations in production (only if DB_CONNECTION is set)
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force

    echo "Checking if database needs seeding..."
    php artisan tinker --execute="
        if (\App\Models\Payment::count() === 0) {
            echo 'Wiping and seeding fresh database...';
            // Disable foreign key checks for clean truncation across drivers
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF');
            } elseif (DB::getDriverName() === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            }
            
            // Truncate existing tables to avoid duplicate key violations from a crashed seed
            \App\Models\User::truncate();
            \App\Models\Setting::truncate();
            \App\Models\Room::truncate();
            \App\Models\Tenant::truncate();
            \App\Models\Contract::truncate();
            \App\Models\MaintenanceRequest::truncate();
            \App\Models\Expense::truncate();
            \App\Models\Utility::truncate();
            
            \Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
            echo 'Database seeded successfully.';
        } else {
            echo 'Database already seeded with payments.';
        }
    "
fi

# Cache configuration, routes, and views for production optimization
echo "Optimizing application cache..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start PHP-FPM in the background
echo "Starting PHP-FPM..."
php-fpm -D

# Start Nginx in the foreground
echo "Starting Nginx..."
exec nginx -g "daemon off;"
