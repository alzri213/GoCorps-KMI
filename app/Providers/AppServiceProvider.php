<?php

namespace App\Providers;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Processors\SqlServerProcessor;

use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\ServiceProvider;
use PDO;

class CustomSqlServerProcessor extends SqlServerProcessor
{
    public function processInsertGetId(\Illuminate\Database\Query\Builder $query, $sql, $values, $sequence = null)
    {
        $connection = $query->getConnection();
        $connection->insert($sql, $values);

        try {
            $result = $connection->selectOne('SELECT SCOPE_IDENTITY() AS [id]');
            return is_object($result) && isset($result->id) ? (int) $result->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Connection::resolverFor('sqlsrv', function ($connection, $database, $prefix, array $config = []) {
            try {
                if (extension_loaded('pdo_sqlsrv')) {
                    $host = $config['host'] ?? '127.0.0.1';
                    $user = $config['username'] ?? '';
                    $pass = $config['password'] ?? '';
                    $dsn = "sqlsrv:Server={$host};Database={$database}";
                    $pdo = new PDO($dsn, $user, $pass);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $conn = new SqlServerConnection($pdo, $database, $prefix, $config);
                    $conn->setPostProcessor(new CustomSqlServerProcessor());
                    return $conn;
                }
            } catch (\Throwable $e) {
                // fallback to ODBC below
            }

            $host = $config['host'] ?? 'Zreekx\\SQLEXPRESS';
            $username = $config['username'] ?? '';
            $password = $config['password'] ?? '';

            if (!empty($username)) {
                $dsn = "odbc:Driver={ODBC Driver 17 for SQL Server};Server={$host};Database={$database};Uid={$username};Pwd={$password};";
            } else {
                $dsn = "odbc:Driver={ODBC Driver 17 for SQL Server};Server={$host};Database={$database};Trusted_Connection=yes;";
            }

            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn = new SqlServerConnection($pdo, $database, $prefix, $config);
            $conn->setPostProcessor(new CustomSqlServerProcessor());
            return $conn;
        });
    }
}
