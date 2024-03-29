<?php
require_once("vendor/autoload.php");
require_once("abi_encoder.php");
require_once($GLOBALS["OpenCEX_common_impl"]);
require_once("SafeMath.php");
require_once("tokens.php");
require_once("blockchain_manager.php");
use kornrunner\Ethereum\Address as OpenCEX_Ethereum_Address;
use kornrunner\Ethereum\Transaction as OpenCEX_Ethereum_Transaction;

final class OpenCEX_WalletManager{
	private readonly string $private_key;
	public readonly string $address;
	private readonly string $chke20balance;
	private readonly OpenCEX_abi_encoder $encoder;
	private readonly OpenCEX_safety_checker $ctx;
	private readonly OpenCEX_BlockchainManagerWrapper $blockchain_manager;
	
	function __construct(OpenCEX_safety_checker $ctx, OpenCEX_BlockchainManagerWrapper $blockchain_manager, string $private_key = ""){
		$ctx->usegas(1);
		if($private_key == ""){
			$this->private_key = $ctx->safe_getenv("OpenCEX_wallet_key");
		} else{
			$this->private_key = $private_key;
		}
		$this->blockchain_manager = $blockchain_manager;
		$this->ctx = $ctx;
		$this->address = "0x" . (new OpenCEX_Ethereum_Address($this->private_key))->get();
		$this->chke20balance = "0x70a08231000000000000000000000000" . substr($this->address, 2);
		$this->encoder = new OpenCEX_abi_encoder($ctx);
	}
	
	public function balanceOf(string $token_addy){
		$this->ctx->usegas(1);
		$this->encoder->chkvalidaddy($token_addy, false);
		return $this->blockchain_manager->eth_call(["from" => "0x0000000000000000000000000000000000000000", "to" => $token_addy, "data" => $this->chke20balance]);
	}
	
	public function nativeBalance($token_addy){
		$this->ctx->usegas(1);
		return $this->blockchain_manager->eth_getBalance($this->address);
	}
	
	public function signTransactionIMPL(OpenCEX_Ethereum_Transaction $transaction, int $chainid){
		$this->ctx->usegas(1);
		return "0x" . $transaction->getRaw($this->private_key, $chainid);
	}
	
	public function sendTransactionIMPL(OpenCEX_Ethereum_Transaction $transaction, int $chainid){
		$this->ctx->usegas(1);
		return $this->blockchain_manager->eth_sendRawTransaction("0x" . $transaction->getRaw($this->private_key, $chainid));
	}
}

final class OpenCEX_SmartWalletManager{
	private readonly OpenCEX_safety_checker $ctx;
	private readonly OpenCEX_WalletManager $wallet;
	private readonly OpenCEX_BlockchainManager $blockchain_manager;
	private readonly OpenCEX_BatchRequestManager $batch_manager;
	private readonly OpenCEX_BlockchainManagerWrapper $manager_wrapper;
	public readonly int $chainid;
	public readonly string $address;
	function __construct(OpenCEX_safety_checker $ctx, OpenCEX_BlockchainManager $blockchain_manager, string $key = ""){
		$this->ctx = $ctx;
		$ctx->usegas(1);
		$this->blockchain_manager = $blockchain_manager;
		$this->batch_manager = new OpenCEX_BatchRequestManager($ctx);
		$this->manager_wrapper = new OpenCEX_BlockchainManagerWrapper($ctx, $this->batch_manager);
		$this->wallet = new OpenCEX_WalletManager($ctx, $this->manager_wrapper, $key);
		$this->address = $this->wallet->address;
		$this->chainid = $blockchain_manager->chainid;
	}
	
	public function borrow($callback, ...$args){
		$this->ctx->usegas(1);
		$temp = new OpenCEX_BlockchainManagerWrapper($this->ctx, $this->batch_manager);
		$ret = $callback($temp, ...$args);
		$temp->invalidate();
		return [$ret, $this->batch_manager->execute($this->blockchain_manager)];
	}
	
	public function sendTransactionIMPL(OpenCEX_Ethereum_Transaction $transaction){
		$this->ctx->usegas(1);
		$this->wallet->sendTransactionIMPL($transaction, $this->blockchain_manager->chainid);
		return $this->batch_manager->execute($this->blockchain_manager)[0];
	}
	
	public function signTransactionIMPL(OpenCEX_Ethereum_Transaction $transaction){
		$this->ctx->usegas(1);
		return $this->wallet->signTransactionIMPL($transaction, $this->blockchain_manager->chainid);
	}
	
	public function reconstruct(string $key = ""){
		$this->ctx->usegas(1);
		return new OpenCEX_SmartWalletManager($this->ctx, $this->blockchain_manager, $key);
	}
	public function balanceOf(string $token_addy){
		$this->ctx->usegas(1);
		$this->wallet->balanceOf($token_addy);
		return OpenCEX_uint::init($this->ctx, $this->batch_manager->execute($this->blockchain_manager)[0]);
	}
	public function safeNonce(OpenCEX_L1_context $l1ctx, OpenCEX_uint $reported){
		$miniaddr = substr($this->address, 2);
		$strnonce = strval($reported);
		$selector = implode([" WHERE Blockchain = ", $this->chainid , " AND Address = '", $miniaddr, "';"]);
		$result = $l1ctx->safe_query("SELECT ExpectedValue FROM Nonces" . $selector);
		if($result->num_rows == 0){
			$l1ctx->safe_query(implode(["INSERT INTO Nonces (ExpectedValue, Blockchain, Address) VALUES (", $strnonce, ", ", $this->chainid, ", '", $miniaddr, "');"]));
			return $reported; //Use latest nonce
		} else{
			//Fetch nonce from database
			$this->ctx->check_safety($result->num_rows == 1, "Accidental transaction cancellation prevention database corrupted!");
			$result = OpenCEX_uint::init($this->ctx, $this->ctx->convcheck2($result->fetch_assoc(), "ExpectedValue"));
			$result = $result->add(OpenCEX_uint::init($this->ctx, "1"));
			
			//Perform safety checks
			$this->ctx->check_safety_2($reported->comp($result) == 1, "Exchange wallet compromise suspected!");
			
			//Update nonce in database
			$l1ctx->safe_query(implode(["UPDATE Nonces SET ExpectedValue = '", strval($result), "'", $selector]));
			
			return $result;
		}
	}
}
define("OpenCEX_chainids", [24734 => "mintme", 137 => "polygon"]);
define("OpenCEX_tokenchains", ["PolyEUBI" => "polygon"]);
final class OpenCEX_native_token extends OpenCEX_token{
	private readonly OpenCEX_abi_encoder $encoder;
	private readonly OpenCEX_SmartWalletManager $manager;
	private readonly string $requestPrefix;
	public function __construct(OpenCEX_L1_context $l1ctx, string $name, OpenCEX_SmartWalletManager $manager){
		parent::__construct($l1ctx, $name);
		$this->safety_checker->usegas(1);
		$this->encoder = new OpenCEX_abi_encoder($this->safety_checker);
		$this->manager = $manager;
		$this->requestPrefix = implode([$this->safety_checker->safe_getenv("OpenCEX_worker"), "/", urlencode(strval($this->safety_checker->safe_getenv("OpenCEX_shared_secret"))), "/sendAndCreditWhenSecure/"]);
	}
	public function send(int $from, string $address, OpenCEX_uint $amount, bool $sync = true){
		$this->safety_checker->usegas(1);
		
		//Prepare transaction
		$this->encoder->chkvalidaddy($address, false);
		$transaction = ["from" => $this->manager->address, "to" => $address, "value" => $amount->tohex()];
		
		//Get gas price, gas estimate, and transaction nonce
		$chainquotes = $this->manager->borrow(function(OpenCEX_BlockchainManagerWrapper $wrapper, string $address2, OpenCEX_uint $amount2, $transaction2, string $address3){
			$wrapper->eth_getTransactionCount($address3);
			$wrapper->eth_estimateGas($transaction2);
			$wrapper->eth_gasPrice();
		}, $address, $amount, $transaction, $this->manager->address)[1];
		
		$chainquotes[0] = $this->manager->safeNonce($this->ctx, $chainquotes[0]);
		$this->creditordebit($from, $amount->add($chainquotes[1]->mul($chainquotes[2])), false, $sync);
		$this->manager->sendTransactionIMPL(new OpenCEX_Ethereum_Transaction($chainquotes[0]->tohex(), $chainquotes[2]->tohex(), $chainquotes[1]->tohex(), $address, $transaction["value"]));
		
	}
	public function sweep(int $from){
		$this->safety_checker->usegas(1);
		$chainquotes = $this->manager->borrow(function(OpenCEX_BlockchainManagerWrapper $wrapper, string $address3){
			$wrapper->eth_getTransactionCount($address3);
			$wrapper->eth_gasPrice();
			$wrapper->eth_getBalance($address3);
		}, $this->manager->address)[1];
		
		$chainquotes[0] = $this->manager->safeNonce($this->ctx, $chainquotes[0]);
		$remains = $chainquotes[2]->sub($chainquotes[1]->mul(OpenCEX_uint::init($this->safety_checker, "21000")), "Amount not enough to cover blockchain fee!");
		$this->safety_checker->check_safety(array_key_exists($this->manager->chainid, OpenCEX_chainids), "Invalid chainid!");
		file_get_contents(implode([$this->requestPrefix, OpenCEX_chainids[$this->manager->chainid], "/", $this->manager->signTransactionIMPL(new OpenCEX_Ethereum_Transaction($chainquotes[0]->tohex(), $chainquotes[1]->tohex(), "0x5208", $this->manager->reconstruct()->address, $remains->tohex())), "/", strval($from), "/", $this->name, "/", strval($remains)]));
	}
}
final class OpenCEX_erc20_token extends OpenCEX_token{
	private readonly string $token_address;
	private readonly string $abi;
	private readonly OpenCEX_abi_encoder $encoder;
	private readonly OpenCEX_SmartWalletManager $manager;
	private readonly OpenCEX_token $gastoken;
	private readonly string $tracked;
	private readonly string $singleton;
	private readonly string $formattedTokenAddress;
	private readonly string $abi2;
	private readonly string $requestPrefix;
	
	public function __construct(OpenCEX_L1_context $l1ctx, string $name, OpenCEX_SmartWalletManager $manager, string $token_address, OpenCEX_token $gastoken){
		parent::__construct($l1ctx, $name);
		$this->safety_checker->usegas(1);
		$this->encoder = new OpenCEX_abi_encoder($this->safety_checker);
		$this->tracked = $manager->address;
		$manager = $manager->reconstruct();
		$this->formattedTokenAddress = $this->encoder->chkvalidaddy($token_address);
		$postfix = $this->formattedTokenAddress . $this->encoder->chkvalidaddy($this->tracked);
		$this->abi = "0x64d7cd50" . $postfix;
		$this->abi2 = implode(["0xaec6ed90", $this->encoder->chkvalidaddy($manager->address), $postfix]);
		$this->manager = $manager;
		$this->token_address = $token_address;
		$this->gastoken = $gastoken;
		$this->singleton = ($this->manager->chainid == 137) ? "0xed91faa6efa532b40f6a1bff3cab29260ebabd21" : "0x9f46db28f5d7ef3c5b8f03f19eea5b7aa8621349";
		$this->requestPrefix = implode([$this->safety_checker->safe_getenv("OpenCEX_worker"), "/", urlencode(strval($this->safety_checker->safe_getenv("OpenCEX_shared_secret"))), "/sendAndCreditWhenSecure/"]);
	}
	public function send(int $from, string $address, OpenCEX_uint $amount, bool $sync = true){
		$this->safety_checker->usegas(1);
		
		//Prepare transaction
		$this->encoder->chkvalidaddy($address, false);
		$transaction = ["from" => $this->manager->address, "to" => $this->token_address, "data" => $this->encoder->encode_erc20_transfer($address, $amount)];
		
		//Get gas price, gas estimate, and transaction nonce
		$chainquotes = $this->manager->borrow(function(OpenCEX_BlockchainManagerWrapper $wrapper, string $address2, OpenCEX_uint $amount2, $transaction2, string $address3){
			$wrapper->eth_getTransactionCount($address3);
			$wrapper->eth_estimateGas($transaction2);
			$wrapper->eth_gasPrice();
		}, $address, $amount, $transaction, $this->manager->address)[1];
		
		$chainquotes[0] = $this->manager->safeNonce($this->ctx, $chainquotes[0]);
		$this->gastoken->creditordebit($from, $chainquotes[1]->mul($chainquotes[2]), false, $sync);
		$this->creditordebit($from, $amount, false, $sync);
		
		$this->manager->sendTransactionIMPL(new OpenCEX_Ethereum_Transaction($chainquotes[0]->tohex(), $chainquotes[2]->tohex(), $chainquotes[1]->tohex(), $transaction["to"], "0", $transaction["data"]));
		
	}
	
	public function sweep(int $from){
		$this->safety_checker->usegas(1);
		
		$balance2 = OpenCEX_uint::init($this->safety_checker, $this->manager->borrow(function(OpenCEX_BlockchainManagerWrapper $wrapper, string $x, string $y){
			$wrapper->eth_call(["from" => "0x0000000000000000000000000000000000000000", "to" => $x, "data" => $y]);
		}, $this->singleton, $this->abi2)[1][0]);
		$transaction = ["from" => $this->manager->address, "to" => $this->singleton, "data" => ($this->abi . $this->encoder->pad256($balance2->tohex(false)))];
		$chainquotes = $this->manager->borrow(function(OpenCEX_BlockchainManagerWrapper $wrapper, string $address3, $transaction2){
			$wrapper->eth_getTransactionCount($address3);
			$wrapper->eth_gasPrice();
			$wrapper->eth_estimateGas($transaction2);
		}, $this->manager->address, $transaction)[1];
		
		$chainquotes[0] = $this->manager->safeNonce($this->ctx, $chainquotes[0]);
		$this->gastoken->creditordebit($from, $chainquotes[1]->mul($chainquotes[2]), false, true);
		$this->safety_checker->check_safety(array_key_exists($this->manager->chainid, OpenCEX_chainids), "Invalid chainid!");
		$signed = $this->manager->signTransactionIMPL(new OpenCEX_Ethereum_Transaction($chainquotes[0]->tohex(), $chainquotes[1]->tohex(), $chainquotes[2]->tohex(), $this->singleton, "", $transaction["data"]));
		$this->ctx->unlock_tables();
		
		file_get_contents(implode([$this->requestPrefix, OpenCEX_chainids[$this->manager->chainid], "/", urlencode($signed), "/", strval($from), "/", $this->name, "/", strval($balance2)]));
	}
}
?>