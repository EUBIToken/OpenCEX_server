<?php
set_time_limit(0);
header("Access-Control-Allow-Origin: https://exchange.polyeubitoken.com");
header("Access-Control-Allow-Credentials: true");
$leaked_ctx = null;
$OpenCEX_anything_locked = false;
$OpenCEX_tempgas = false;
//Treat warnings as errors
function OpenCEX_error_handler($errno, string $message, string $file, int $line, $context = NULL)
{
	//NOTE: we treat all warnings as errors!
	$temp_leaked_context = null;
	if(array_key_exists("leaked_ctx", $GLOBALS)){
		$temp_leaked_context = $GLOBALS["leaked_ctx"];
	}
	if(!is_null($temp_leaked_context)){
		$temp_leaked_context->destroy(false);
	}
	die('{"status": "error", "reason": "Unexpected internal server error at ' . escapeJsonString($file) . ' line ' . strval($line) . ': ' . escapeJsonString($message) . '"}');
	return true;
}


set_error_handler("OpenCEX_error_handler");

//Safety checking
$OpenCEX_temp_check = getenv("OpenCEX_permitted_domain");

if(!array_key_exists("HTTP_HOST", $_SERVER)){
	die('{"status": "error", "reason": "Missing HTTP Host information!"}');
}

if(!array_key_exists("HTTP_ORIGIN", $_SERVER)){
	die('{"status": "error", "reason": "Missing HTTP Origin information!"}');
}

if($_SERVER['HTTP_HOST'] !== $OpenCEX_temp_check){
	//NOTE: This safety check prevents cloudflare-stripping attacks by
	//accessing the herokuapp domain directly!
	die('{"status": "error", "reason": "Unsupported domain!"}');
}
$OpenCEX_temp_check = getenv("OpenCEX_permitted_origin");

if($_SERVER['HTTP_ORIGIN'] !== $OpenCEX_temp_check){
	//NOTE: This safety check prevents cloudflare-stripping attacks by
	//accessing the herokuapp domain directly!
	die('{"status": "error", "reason": "Unsupported origin!"}');
}
if(!array_key_exists("OpenCEX_request_body", $_POST)){
	die('{"status": "error", "reason": "Missing request body!"}');
}

if(strlen($_POST["OpenCEX_request_body"]) > 65536){
	die('{"status": "error", "reason": "Excessively long request body!"}');
}

$OpenCEX_temp_check = json_decode($_POST["OpenCEX_request_body"], true);

if(is_null($OpenCEX_temp_check)){
	die('{"status": "error", "reason": "Invalid request!"}');
}

$requests_count = 1;
if(is_array($OpenCEX_temp_check)){
	//shortcut
	$requests_count = count($OpenCEX_temp_check);
	if($requests_count == 0){
		die('{"status": "success", "returns": []}');
	}
} else{
	//put request in array, if it's not an array
	$OpenCEX_temp_check = [$OpenCEX_temp_check];
}

$OpenCEX_common_impl = "common.php";

require_once("../" . $GLOBALS["OpenCEX_common_impl"]);
require_once("../matching_engine.php");
require_once("../TokenOrderBook.php");
require_once("../SafeMath.php");
require_once("../tokens.php");
require_once("../wallet_manager.php");
require_once("../blockchain_manager.php");

abstract class OpenCEX_request{
	public abstract function execute(OpenCEX_L3_context $ctx, $args);
	public function captcha_required(){
		return false;
	}
	
	//Requests that result in external interaction are not batchable
	public function batchable(){
		return true;
	}
}

function check_safety_3($ctx, $args, $exception = NULL){
	$ctx->check_safety(is_array($args), "Arguments must be array!");
	foreach($args as $key => $value){
		$ctx->check_safety(is_string($key), "Key must be string!");
		if($key !== $exception){
			$ctx->check_safety(is_string($value), "Value must be string!");
		}
	}
}

abstract class OpenCEX_depositorwithdraw extends OpenCEX_request{
	protected function get_token(OpenCEX_SmartWalletManager $manager, string $token, OpenCEX_L2_context $ctx){
		$ctx->usegas(1);
		$ctx->ledgers_locked = true; //Override locking
		$token_address;
		switch($token){
			case "PolyEUBI":
				$token_address = "0x553E77F7f71616382B1545d4457e2c1ee255FA7A";
				break;
			case "EUBI":
				$token_address = "0x8afa1b7a8534d519cb04f4075d3189df8a6738c1";
				break;
			case "1000x":
				$token_address = "0x7b535379bbafd9cd12b35d91addabf617df902b2";
				break;
			default:
				$token_address = "";
				break;
		}
		return $ctx->borrow_sql(function(OpenCEX_L1_context $l1ctx, string $token2, OpenCEX_SmartWalletManager $manager2, string $token_address_2){
			$l1ctx->safe_query("LOCK TABLES Balances WRITE, Nonces WRITE;");
			switch($token2){
				case "PolyEUBI":
					return new OpenCEX_erc20_token($l1ctx, $token2, $manager2, $token_address_2, new OpenCEX_pseudo_token($l1ctx, "MATIC"));
				case "EUBI":
				case "1000x":
					return new OpenCEX_erc20_token($l1ctx, $token2, $manager2, $token_address_2, new OpenCEX_pseudo_token($l1ctx, "MintME"));
				default:
					$ret2 = new OpenCEX_native_token($l1ctx, $token2, $manager2);
					return $ret2;
			}	
		}, $token, $manager, $token_address);
		
	}
	
	protected function resolve_blockchain(string $token, OpenCEX_safety_checker $safe){
		$safe->usegas(1);
		switch($token){
			case "PolyEUBI":
			case "MATIC":
				return new OpenCEX_BlockchainManager($safe, 137, "https://polygon-rpc.com");
			case "MintME":
			case "EUBI":
			case "1000x":
				return new OpenCEX_BlockchainManager($safe, 24734, "https://node1.mintme.com:443");
			case "BNB":
				return new OpenCEX_BlockchainManager($safe, 56, "https://bsc-dataseed.binance.org/");
			default:
				$safe->die2("Unsupported token!");
				return;
		}
	}
}

$request_methods = ["non_atomic" => new class extends OpenCEX_request{
	//This special request indicates that we are doing a non-atomic request.
	public function execute(OpenCEX_L3_context $ctx, $args){
		
	}
}, "create_account" => new class extends OpenCEX_request{
	public function execute(OpenCEX_L3_context $ctx, $args){
		//Safety checks
		$ctx->check_safety_2($ctx->safe_getenv("OpenCEX_devserver") == "true", "Account creation not allowed on dev server!");
		check_safety_3($ctx, $args);
		$ctx->check_safety(count($args) === 3, "Account creation requires 3 arguments!");
		$ctx->check_safety(array_key_exists("username", $args), "Account creation error: missing username!");
		$ctx->check_safety(array_key_exists("password", $args), "Account creation error: missing password!");
		
		//More safety checks
		$username = $args["username"];
		$password = $args["password"];
		$ctx->check_safety(strlen($username) < 256, "Account creation error: username too long!");
		$ctx->check_safety(strlen($username) > 3, "Account creation error: username too short!");
		$ctx->check_safety(strlen($password) > 8, "Account creation error: password too short!");
		
		//Do some work
		$ctx->session_create($ctx->emit_create_account($username, $password), true);
	}
	public function captcha_required(){
		return true;
	}
}, "login" => new class extends OpenCEX_request{
	public function execute(OpenCEX_L3_context $ctx, $args){
		//Safety checks
		check_safety_3($ctx, $args, "renember");
		$ctx->check_safety(count($args) === 4, "Login requires 4 arguments!");
		$ctx->check_safety(is_bool($args["renember"]), "Login error: renember flag must be boolean!");
		
		//Process user login
		$ctx->login_user($args["username"], $args["password"], $args["renember"]);
	}
	public function captcha_required(){
		return true;
	}
}, "flush" => new class extends OpenCEX_request{
	public function execute(OpenCEX_L3_context $ctx, $args){
		$ctx->flush_outstanding();
	}
}, "client_name" => new class extends OpenCEX_request{
	public function execute(OpenCEX_L3_context $ctx, $args){
		return $ctx->get_cached_username();
	}
}, "logout" => new class extends OpenCEX_request{
	public function execute(OpenCEX_L3_context $ctx, $args){
		return $ctx->destroy_active_session();
	}
}, "balances" => new class extends OpenCEX_request{
	public function execute(OpenCEX_L3_context $ctx, $args){
		$result = $ctx->fetchBalancesStream($ctx->get_cached_user_id());
		$ret = [];
		$found_coins = [];
		if($result->num_rows > 0){
			while($row = $result->fetch_assoc()) {
				$coin2 = $ctx->convcheck2($row, "Coin");
				array_push($found_coins, $coin2);
				array_push($ret, [$coin2, $ctx->convcheck2($row, "Balance")]);
			}
		}
		$allowed_tokens = $ctx->safe_decode_json($ctx->safe_getenv("OpenCEX_tokens"));
		foreach($allowed_tokens as $token){
			if(!in_array($token, $found_coins, true)){
				array_push($ret, [$token, "0"]);
			}
		}
		return $ret;
	}
}, "get_chart" => new class extends OpenCEX_request{
	public function execute(OpenCEX_L3_context $ctx, $args){
		$ctx->check_safety(count($args) == 2, "Chart loading requires 2 arguments!");
		check_safety_3($ctx, $args, null);
		$ctx->check_safety(array_key_exists("primary", $args), "Chart loading must specify primary token!");
		$ctx->check_safety(array_key_exists("secondary", $args), "Chart loading must specify secondary token!");
		return $ctx->loadcharts($args['primary'], $args['secondary']);
	}
}, "eth_deposit_address" => new class extends OpenCEX_request{
	public function execute(OpenCEX_L3_context $ctx, $args){
		$safe = new OpenCEX_safety_checker($ctx);
		return (new OpenCEX_WalletManager($safe, new OpenCEX_BlockchainManagerWrapper($safe, new OpenCEX_FullBlockchainManager()), $ctx->cached_eth_deposit_key()))->address;
	}
}, "withdraw" => new class extends OpenCEX_depositorwithdraw{
	public function execute(OpenCEX_L3_context $ctx, $args){
		$ctx->check_safety(count($args) == 3, "Withdrawal requires 3 arguments!");
		check_safety_3($ctx, $args, null);
		$ctx->check_safety(array_key_exists("token", $args), "Withdrawal must specify token!");
		$ctx->check_safety(array_key_exists("amount", $args), "Withdrawal must specify amount!");
		$ctx->check_safety(array_key_exists("address", $args), "Withdrawal must specify recipient address!");
		$userid = $ctx->get_cached_user_id();
		$ctx->check_safety_2($userid == 0, "Illegal UserID!");
		
		$safe = new OpenCEX_safety_checker($ctx);
		$wallet = new OpenCEX_SmartWalletManager($safe, $this->resolve_blockchain($args["token"], $safe));
		$token = $this->get_token($wallet, $args["token"], $ctx);
		
		
		//We add some gas, so we don't fail due to insufficent gas.
		$ctx->usegas(-1000);
		$GLOBALS["OpenCEX_tempgas"] = true;
		$token->send($userid, $args["address"], OpenCEX_uint::init($safe, $args["amount"]));
	}
	function batchable(){
		return false;
	}
}, "load_active_orders" => new class extends OpenCEX_request{
	public function execute(OpenCEX_L3_context $ctx, $args){
		return $ctx->borrow_sql(function(OpenCEX_L1_context $l1ctx, int $userid2){
			$l1ctx->safe_query("LOCK TABLE Orders READ;");
			$result = $l1ctx->safe_query(implode(["SELECT Pri, Sec, Price, InitialAmount, TotalCost, Id, Buy FROM Orders WHERE PlacedBy = ", strval($userid2), ";"]));
			$l1ctx->safe_query("UNLOCK TABLES;");
			$ret = [];
			if($result->num_rows > 0){
				$checker = $l1ctx->get_safety_checker();
				while($row = $result->fetch_assoc()) {
					$Pri = $checker->convcheck2($row, "Pri");
					$Sec = $checker->convcheck2($row, "Sec");
					$Price = $checker->convcheck2($row, "Price");
					$InitialAmount = $checker->convcheck2($row, "InitialAmount");
					$TotalCost = $checker->convcheck2($row, "TotalCost");
					$Id = $checker->convcheck2($row, "Id");
					$Buy = $checker->convcheck2($row, "Buy");
					array_push($ret, [$Pri, $Sec, $Price, $InitialAmount, $TotalCost, $Id, $Buy == "1"]);
				}
			}
			return $ret;
		}, $ctx->get_cached_user_id());
	}
	function batchable(){
		return false;
	}
}];

//Continue validating request
$not_multiple_requests = $requests_count < 2;
$captcha_caught = false;
$non_atomic = false;
foreach($OpenCEX_temp_check as $singular_request){
	if(!array_key_exists("method", $singular_request)){
		die('{"status": "error", "reason": "Request method missing!"}');
	}
	
	if(!array_key_exists($singular_request["method"], $request_methods)){
		die('{"status": "error", "reason": "Request method not defined!"}');
	}
	
	if($singular_request["method"] == "non_atomic"){
		$non_atomic = true;
	}
	
	if(!($not_multiple_requests || $request_methods[$singular_request["method"]]->batchable() || $non_atomic)){
		die('{"status": "error", "reason": "Request not batchable!"}');
	}
	
	if($request_methods[$singular_request["method"]]->captcha_required()){
		if($captcha_caught){
			die('{"status": "error", "reason": "Multiple captcha-protected requests in batch!"}');
		}
		$captcha_caught = true;
	}
}

function escapeJsonString($value) { # list from www.json.org: (\b backspace, \f formfeed)
	$escapers = array("\\", "/", "\"", "\n", "\r", "\t", "\x08", "\x0c");
	$replacements = array("\\\\", "\\/", "\\\"", "\\n", "\\r", "\\t", "\\f", "\\b");
	$result = str_replace($escapers, $replacements, $value);
	return $result;
}

//Begin request execution
$return_array = [];
try{
	//Setup context
	$ctx = new OpenCEX_L3_context();
	$leaked_ctx = new OpenCEX_safety_checker($ctx);
	$ctx->begin_emitting();
	
	//Gas refund for standard batches
	if($non_atomic || $not_multiple_requests){
		$ctx->usegas(-1000);
	}
	
	//Execute requests
	foreach($OpenCEX_temp_check as $singular_request){
		$ctx->usegas(1);
		$data = null;
		if(array_key_exists("data", $singular_request)){
			$data = $singular_request["data"];
		} else{
			$data = [];
		}
		if($request_methods[$singular_request["method"]]->captcha_required()){
			try{
				$ctx->check_safety(array_key_exists('captcha', $data), "Captcha required!");
				$ctx->check_safety(is_string($data['captcha']), "ReCaptcha response must be string!");
				$captcha_result = $ctx->safe_decode_json(file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, stream_context_create([
					'http' => [
						'method' => 'POST',
						'header'  => "Content-type: application/x-www-form-urlencoded",
						'content' => http_build_query([
							'secret' => $ctx->safe_getenv('OpenCEX_recaptcha_secret'), 'response' => $data['captcha']
						])
					]
				])));
				$ctx->check_safety(array_key_exists('success', $captcha_result), "Invalid ReCaptcha response!");
				$ctx->check_safety($captcha_result['success'], "Captcha required!");
			} catch (OpenCEX_assert_exception $e){
				throw $e;
			} catch (Exception $e){
				$ctx->die2("Captcha required!");
			}
		}
		
		array_push($return_array, $request_methods[$singular_request["method"]]->execute($ctx, $data));
		
		
		//In an atomic batch, if 1 request fails, all previous requests are reverted.
		//In a standard batch, if 1 request fails, the results of previous requests are preserved.
		//We need to flush outstanding changes in a standard batch after each request.
		if($ctx->anything_locked()){
			$ctx->unlock_tables();
		} else if($non_atomic){
			$ctx->flush_outstanding();
		}
		
		if($GLOBALS["OpenCEX_tempgas"]){
			$ctx->usegas(1000);
		}
	}
	
	//Flush and destroy context
	$leaked_ctx = null;
	$ctx->finish_emitting();
} catch (OpenCEX_assert_exception $e){
	if(getenv("OpenCEX_devserver") === "true"){
		$e = strval($e);
	} else{
		$e = $e->getMessage();
	}
	die('{"status": "error", "reason": "' . escapeJsonString($e) . '"}');
} catch (Exception $e){
	//NOTE: if we fail due to unexpected exception, we must destroy the context!
	//Automatic context destruction only occours for OpenCEX_assert_exceptions.
	if(!is_null($leaked_ctx)){
		$leaked_ctx->destroy();
	}
	if(getenv("OpenCEX_devserver") === "true"){
		$e = strval($e);
	} else{
		$e = $e->getMessage();
	}
	$fail_message = '{"status": "error", "reason": "Unexpected internal server error: ' . escapeJsonString($e) . '"}';
	die($fail_message);
}

die(implode(['{"status": "success", "returns": ', json_encode($return_array), "}"]));

?>
