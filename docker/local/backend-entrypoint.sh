#!/bin/sh
set -e

echo "[backend-entrypoint] Waiting for database..."

php -r '
$envFile = "/app/.env";
$envValues = file_exists($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];
$read = static function (string $key, ?string $default = null) use ($envValues): ?string {
    $value = getenv($key);
    if ($value !== false && $value !== "") {
        return $value;
    }

    $value = $envValues[$key] ?? null;
    if ($value === null || $value === "") {
        return $default;
    }

    return trim((string) $value, "\" ");
};

$host = $read("DB_HOST", "db");
$port = $read("DB_PORT", "3306");
$database = $read("DB_DATABASE", "ficker");
$username = $read("DB_USERNAME", "root");
$password = $read("DB_PASSWORD", "");

$maxAttempts = 60;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    try {
        new PDO("mysql:host={$host};port={$port};dbname={$database}", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        fwrite(STDOUT, "[backend-entrypoint] Database is ready.\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDOUT, "[backend-entrypoint] Attempt {$attempt}/{$maxAttempts} failed. Retrying...\n");
        sleep(2);
    }
}

fwrite(STDERR, "[backend-entrypoint] Database connection timeout.\n");
exit(1);
'

if [ ! -f /app/vendor/autoload.php ]; then
  echo "[backend-entrypoint] Installing Composer dependencies..."
  composer install --no-interaction --optimize-autoloader --dev
fi

echo "[backend-entrypoint] Clearing Laravel caches..."
php artisan optimize:clear

echo "[backend-entrypoint] Running migrations..."
php artisan migrate --force

echo "[backend-entrypoint] Seeding base catalogs..."
php artisan db:seed --class=Database\\Seeders\\BaseCatalogSeeder --force

exec /entrypoint "$@"
