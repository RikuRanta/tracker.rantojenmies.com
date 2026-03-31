<?php

/**
 * @author 
 * @copyright 2012
 */

class Mysql extends PDO {

    public function __construct() {
	
		include('conf/config.php');

        parent::__construct($dsn,$db_user,$db_passwd);

        try {
            $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        catch (PDOException $e) {
            throw new Exception("Database connection failed.");
        }
		
    }

}
?>