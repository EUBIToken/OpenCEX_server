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
}
define("OpenCEX_chainids", [24734 => "mintme", 137 => "polygon"]);
define("OpenCEX_tokenchains", ["PolyEUBI" => "polygon"]);
final class OpenCEX_native_token extends OpenCEX_token{
	private readonly OpenCEX_abi_encoder $encoder;
	private readonly OpenCEX_SmartWalletManager $manager;
	public function __construct(OpenCEX_L1_context $l1ctx, string $name, OpenCEX_SmartWalletManager $manager){
		parent::__construct($l1ctx, $name);
		$this->safety_checker->usegas(1);
		$this->encoder = new OpenCEX_abi_encoder($this->safety_checker);
		$this->manager = $manager;
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
		$remains = $chainquotes[2]->sub($chainquotes[1]->mul(OpenCEX_uint::init($this->safety_checker, "21000")), "Amount not enough to cover blockchain fee!");
		$this->safety_checker->check_safety(array_key_exists($this->manager->chainid, OpenCEX_chainids), "Invalid chainid!");
		file_get_contents(implode([((getenv('OpenCEX_devserver') === "true") ? "https://opencex-dev-worker.herokuapp.com/" : "https://opencex-prod-worker.herokuapp.com/"), urlencode(strval(getenv("OpenCEX_shared_secret"))), "/sendAndCreditWhenSecure/", OpenCEX_chainids[$this->manager->chainid], "/", $this->manager->signTransactionIMPL(new OpenCEX_Ethereum_Transaction($chainquotes[0]->tohex(), $chainquotes[1]->tohex(), "0x5208", $this->manager->reconstruct()->address, $remains->tohex())), "/", strval($from), "/", $this->name, "/", strval($remains)]));
	}
}
final class OpenCEX_erc20_token extends OpenCEX_token{
	private readonly string $token_address;
	private readonly string $abi;
	private readonly OpenCEX_abi_encoder $encoder;
	private readonly OpenCEX_SmartWalletManager $manager;
	private readonly OpenCEX_token $gastoken;
	private readonly string $tracked;
	private readonly string $actual;
	public function __construct(OpenCEX_L1_context $l1ctx, string $name, OpenCEX_SmartWalletManager $manager, string $token_address, OpenCEX_token $gastoken){
		parent::__construct($l1ctx, $name);
		$this->safety_checker->usegas(1);
		$this->encoder = new OpenCEX_abi_encoder($this->safety_checker);
		$this->tracked = $manager->address;
		$manager = $manager->reconstruct();
		$this->abi = implode(["0x8a738683", $this->encoder->chkvalidaddy($token_address), $this->encoder->chkvalidaddy($manager->address)]);
		$this->manager = $manager;
		$ret2 = $manager->borrow(function(OpenCEX_BlockchainManagerWrapper $wrapper, OpenCEX_SmartWalletManager $manager2, string $tracked){
			$singleton = OpenCEX_chainids[$manager2->chainid] === "polygon" ? "0x18a2db82061979e6e7d963cc3a21bcf6b6adef9b" : "0x98ecc85b24e0041c208c21aafba907cd74f9ded6";
			$encoded = implode(["0xe8aaeb54000000000000000000000000", substr($manager2->address, 2), "000000000000000000000000", substr($tracked, 2)]);
			$wrapper->eth_call(["from" => "0x0000000000000000000000000000000000000000", "to" => $singleton, "data" => $encoded]);
		}, $manager, $this->tracked);
		$this->actual = ;
		$this->token_address = $token_address;
		$this->gastoken = $gastoken;
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
		$this->gastoken->creditordebit($from, $chainquotes[1]->mul($chainquotes[2]), false, $sync);
		$this->creditordebit($from, $amount, false, $sync);
		
		$this->manager->sendTransactionIMPL(new OpenCEX_Ethereum_Transaction($chainquotes[0]->tohex(), $chainquotes[2]->tohex(), $chainquotes[1]->tohex(), $transaction["to"], "0", $transaction["data"]));
		
	}
	
	public function sweep(int $from){
		$this->safety_checker->usegas(1);
		$singleton = OpenCEX_chainids[$this->manager->chainid] === "polygon" ? "0x18a2db82061979e6e7d963cc3a21bcf6b6adef9b" : "0x98ecc85b24e0041c208c21aafba907cd74f9ded6";
		$transaction = ["from" => $this->manager->address, "to" => $singleton, "data" => $this->abi];
		$chainquotes = $this->manager->borrow(function(OpenCEX_BlockchainManagerWrapper $wrapper, string $address3, $transaction2){
			$wrapper->eth_getTransactionCount($address3);
			$wrapper->eth_gasPrice();
			$wrapper->eth_estimateGas($transaction2);
			$wrapper->eth_call();
		}, $this->manager->address, $transaction)[1];
		
		$this->gastoken->creditordebit($from, $chainquotes[1]->mul($chainquotes[2]), false, true);
		$this->safety_checker->check_safety(array_key_exists($this->manager->chainid, OpenCEX_chainids), "Invalid chainid!");
		$signed = $this->manager->signTransactionIMPL(new OpenCEX_Ethereum_Transaction($chainquotes[0]->tohex(), $chainquotes[1]->tohex(), $chainquotes[2]->tohex(), $singleton, "", $transaction["data"]));
		file_get_contents(implode([((getenv('OpenCEX_devserver') === "true") ? "https://opencex-dev-worker.herokuapp.com/" : "https://opencex-prod-worker.herokuapp.com/"), urlencode(strval(getenv("OpenCEX_shared_secret"))), "/sendAndCreditWhenSecure/", OpenCEX_chainids[$this->manager->chainid], "/", urlencode($signed), "/", strval($from), "/", $this->name, "/", strval($this->tracked->balanceOf($this->token_address))]));
	}
}
?>