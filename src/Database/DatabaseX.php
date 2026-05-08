<?php

namespace McpServer\Database;

use FishyBoat21\ExtendOrm\Database;
use PDO;

class DatabaseX extends Database{
    public static function GetConnectionStatic():PDO{
        return static::$instance->GetConnection();
    }
}