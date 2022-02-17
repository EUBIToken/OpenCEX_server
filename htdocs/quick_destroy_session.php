<?php
	//NOTE: this is the back-up mean of logging out, if normal logout fails
	$host = getenv("OpenCEX_host");
	$secure = getenv("OpenCEX_secure");
	if(is_string($host) && is_string($secure)){
		setcookie("OpenCEX_session", "", 1, "", $host, $secure === "true", true);
	} else{
		http_response_code(500);
	}
	
?>