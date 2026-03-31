<?php

	function url(){
	  $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
		? $_SERVER['HTTP_HOST']
		: $_SERVER['SERVER_NAME'];
	  return sprintf(
		"%s://%s%s",
		isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
		$host,
		dirname($_SERVER['PHP_SELF'])
	  );
	}

    function _cleanInputs($data) {
        $clean_input = Array();
        if (is_array($data)) {
            foreach ($data as $k => $v) {
				$clean_input[$k] = _cleanInputs($v);
            }
        } else {
            $clean_input = trim(strip_tags($data));
        }
        return $clean_input;
    }

	/* Tarkistetaan kirjautuminen */
	$kirjautunut = false;
	$cookie_name = 'wordpress_logged_in_';
	foreach ($_COOKIE as $name => $value) {
		if (stripos($name,$cookie_name) === 0) {
			$kirjautunut = true;
			break;
		}
	}

?>