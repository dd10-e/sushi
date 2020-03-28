<?php

namespace Sushi;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

trait Sushi
{
    protected static $sushiConnection;

    public function getRows()
    {
        return $this->rows;
    }

    public function getSchema()
    {
        return $this->schema ?? [];
    }

    public static function resolveConnection($connection = null)
    {
        return static::$sushiConnection;
    }

    public static function bootSushi()
    {

        $instance = (new static);

        switch (static::setDatabaseDriver()) {
            case 'pgsql':
                $database = env('DB_DATABASE');

                $instance->setDatabaseConnection($database);
                $instance->migratePgsql();
                
                break;
            case 'sqlite':
                $instance->setSqlite();
                break;
        }

    }

    public static function setDatabaseDriver()
    {
        // @todo add it to config file
        return 'sqlite';
    }

    protected static function setDatabaseConnection($database)
    {
        static::$sushiConnection = app(ConnectionFactory::class)->make([
            'driver' => static::setDatabaseDriver(),
            'database' => $database,
        ]);
    }

    protected static function setSqlite()
    {
        $cacheFileName = config('sushi.cache-prefix', 'sushi').'-'.Str::kebab(str_replace('\\', '', static::class)).'.sqlite';
        $cacheDirectory = realpath(config('sushi.cache-path', storage_path('framework/cache')));
        $cachePath = $cacheDirectory.'/'.$cacheFileName;
        $modelPath = (new \ReflectionClass(static::class))->getFileName();

        $states = [
            'cache-file-found-and-up-to-date' => function () use ($cachePath) {
                static::setDatabaseConnection($cachePath);
            },
            'cache-file-not-found-or-stale' => function () use ($cachePath, $modelPath, $instance) {
                file_put_contents($cachePath, '');

                static::setDatabaseConnection($cachePath);

                $instance->migrateSqlite();

                touch($cachePath, filemtime($modelPath));
            },
            'no-caching-capabilities' => function () use ($instance) {
                static::setDatabaseConnection(':memory:');

                $instance->migrateSqlite();
            },
        ];

        switch (true) {
            case ! property_exists($instance, 'rows'):
                $states['no-caching-capabilities']();
                break;

            case file_exists($cachePath) && filemtime($modelPath) <= filemtime($cachePath):
                $states['cache-file-found-and-up-to-date']();
                break;

            case file_exists($cacheDirectory) && is_writable($cacheDirectory):
                $states['cache-file-not-found-or-stale']();
                break;

            default:
                $states['no-caching-capabilities']();
                break;
        }
    }

    public function migratePgsql()
    {
        // @todo Retablish Sqlite
        // Allow pgsql to refresh excel with UI buttons
        // each buttons should be listed with model with Sushi trait defined
        // and allow us to update data or recreate table

        $rows = $this->getRows();
        $tableName = $this->getTable();

        // Set column containing 'Id' to snake_case
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                if (Str::contains($column, 'Id')) {
                    $rows[$index][Str::snake($column)] = $row[$column];
                    unset($rows[$index][$column]);
                }
            }
        }

        $firstRow = $rows[0];

        if (static::resolveConnection()->getSchemaBuilder()->hasTable($tableName)) {
            static::resolveConnection()->getSchemaBuilder()->dropIfExists($tableName);
        } 

        static::resolveConnection()->getSchemaBuilder()->create($tableName, function ($table) use ($firstRow) {
            // Add the "id" column if it doesn't already exist in the rows.
            if ($this->incrementing && ! in_array($this->primaryKey, array_keys($firstRow))) {
                $table->increments($this->primaryKey);
            }

                // $table->foreignId('window_material_id');

            // foreach (array_keys($firstRow) as $value) {
            //     if (Str::contains($value, 'Id')) {
            //         $table->foreignId(Str::snake($value));
            //     }
            // }

            foreach ($firstRow as $column => $value) {
                switch (true) {
                    case is_int($value):
                        $type = 'integer';
                        break;
                    case is_numeric($value):
                        $type = 'float';
                        break;
                    case is_string($value):
                        // @todo fix
                        if (strlen($value) > 150 ) {
                            $type = 'longText';
                        } else {
                            $type = 'string';
                        }
                        break;
                    case is_object($value) && $value instanceof \DateTime:
                        $type = 'dateTime';
                        break;
                    default:
                        $type = 'string';
                }

                if ($column === $this->primaryKey && $type == 'integer') {
                    $table->increments($this->primaryKey);
                    continue;
                }

                if (Str::contains($column, 'id')) {
                    $table->foreignId(Str::snake($column));
                    continue;
                }
                
                $schema = $this->getSchema();

                $type = $schema[$column] ?? $type;

                $table->{$type}($column)->nullable();
            }

            if ($this->usesTimestamps() && (! in_array('updated_at', array_keys($firstRow)) || ! in_array('created_at', array_keys($firstRow)))) {
                $table->timestamps();
            }
        });

        static::insert($rows);
    }

    public function migrateSqlite()
    {
        $rows = $this->getRows();
        $firstRow = $rows[0];
        $tableName = $this->getTable();

        static::resolveConnection()->getSchemaBuilder()->create($tableName, function ($table) use ($firstRow) {
            // Add the "id" column if it doesn't already exist in the rows.
            if ($this->incrementing && ! in_array($this->primaryKey, array_keys($firstRow))) {
                $table->increments($this->primaryKey);
            }

            foreach ($firstRow as $column => $value) {
                switch (true) {
                    case is_int($value):
                        $type = 'integer';
                        break;
                    case is_numeric($value):
                        $type = 'float';
                        break;
                    case is_string($value):
                        $type = 'string';
                        break;
                    case is_object($value) && $value instanceof \DateTime:
                        $type = 'dateTime';
                        break;
                    default:
                        $type = 'string';
                }

                if ($column === $this->primaryKey && $type == 'integer') {
                    $table->increments($this->primaryKey);
                    continue;
                }

                $schema = $this->getSchema();

                $type = $schema[$column] ?? $type;

                $table->{$type}($column)->nullable();
            }

            if ($this->usesTimestamps() && (! in_array('updated_at', array_keys($firstRow)) || ! in_array('created_at', array_keys($firstRow)))) {
                $table->timestamps();
            }
        });

        static::insert($rows);
    }

    public function usesTimestamps()
    {
        // Override the Laravel default value of $timestamps = true; Unless otherwise set.
        return (new \ReflectionClass($this))->getProperty('timestamps')->class === static::class
            ? parent::usesTimestamps()
            : false;
    }
}
