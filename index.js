"use strict";
console.log('OpenCEX Worker v1.0 - the OpenCEX worker process');
console.log('Made by Jessie Lesbian');
console.log('Email: jessielesbian@protonmail.com Reddit: https://www.reddit.com/u/jessielesbian');
console.log('');
{
	const env = process.env;
	{
		const mysql = require('mysql');
		_sql = mysql.createConnection({host: env.OpenCEX_sqlserver, user: env.OpenCEX_sqluser, password: env.OpenCEX_sqlpassword, database: env.OpenCEX_sqldb, port: 3306, ssl:{ca:require('fs').readFileSync('certificate.txt')}});
		_sqlescape = mysql.escape;
	}
	const sql = _sql;
	_sql = undefined;
	const sqlescape = _sqlescape;
	_sqlescape = undefined;
	
	//Requests made before SQL is connected are admitted here!
	let beforesqlavail = [];
	
	sql.connect(async function(err) {
		if (err) throw err;
		beforesqlavail.forEach(async function(f){
			f();
		});
		beforesqlavail = undefined;
	});
	
	let SQL_locked = false;
	const SQL_queue = [];
	const lockSQL = async function(f){
		if(SQL_locked){
			SQL_queue.push(f);
		} else{
			SQL_locked = true;
			f();
		}
	};
	
	const awaitSQL = async function(f){
		f = async function(){
			lockSQL(f);
		};
		if(beforesqlavail){
			beforesqlavail.push(f);
		} else{
			f();
		}
	};
	
	
	
	//NOTE: f should not be an asynchronous function.
	const useSQL = async function(response, f){
		awaitSQL(async function(){
			//Core safety methods
			const unlockSQL = async function(){
				//If there is nothing waiting to use SQL, unlock!
				//otherwise, execute next SQL-using task without releasing and reacquiring lock!
				const shift = SQL_queue.shift();
				if(shift){
					shift();
				} else{
					sql_locked = false;
				}
			};
			
			let connection_open = true;
			let _tempfuncexport;
			{
				const res2 = response;
				_tempfuncexport = function(msg){
					sql.query("ROLLBACK;");
					unlockSQL();
					if(connection_open){
						res2.write(JSON.stringify({error: msg.toString()}));
						res2.end();
						connection_open = false;

					}
					
					//Throw to abort calling method
					//NOTE: calling method must return after catching exception!
					throw "";
				};
			}
			const fail = _tempfuncexport;
			{
				const res2 = response;
				_tempfuncexport = async function(data, nocommit){
					const handle = async function(err){
						try{
							checkSafety2(err, "Unable to commit MySQL transaction!");	
						} catch (e){
							return;
						}
						
						if(connection_open){
							res2.write(JSON.stringify({returns: data}));
							res2.end();
							connection_open = false;
						}
						
						if(!nocommit){
							unlockSQL();
						}
						
					};
					if(nocommit){
						handle(false);
					} else{
						sql.query("COMMIT;", handle);
					}
				};
			}
			const ret2 = _tempfuncexport;
			_tempfuncexport = undefined;
			response = undefined;
			const safeQuery = async function(query, callback){
				sql.query(query, async function(err, res){
					callback(res);
				});
			};
			
			//Extended safety methods
			const checkSafety = function(exp, msg){
				if(!exp){
					fail(msg);
				}
			};
			
			const checkSafety2 = function(exp, msg){
				if(exp){
					fail(msg);
				}
			};
			
			safeQuery("START TRANSACTION;", async function(){
				f(fail, checkSafety, checkSafety2, safeQuery, ret2);
			});
			
			
		});
	};
	let BigNumber = require('web3-utils').BN;
	
	let http = require('http').createServer(async function(req, res){
		const params = req.url.split('/');
		const chains = [];
		{
			const eth = require('web3-eth');
			chains.polygon = new eth('https://polygon-rpc.com/');
		}
		
		if(params.length < 2){
			res.write('{"error": "Invalid request!"}');
			res.end();
			return;
		} else{
			params.reverse()
			params.pop();
		}
		
		
		const safeshift = function(checkSafety2){
			const result = params.pop();
			checkSafety2(result === undefined, "Not enough parameters!");
			return decodeURIComponent(result);
		};
		
		const methods = {
			sendAndCreditWhenSecure: async function(fail, checkSafety, checkSafety2, safeQuery, ret2){
				//auth/method/tx/account/token/amount
				const BlockchainManager = chains[safeshift(checkSafety2)];
				try{
					checkSafety(BlockchainManager, "Undefined blockchain!");
				} catch{
					return;
				}
				
				
				const tx = safeshift(checkSafety2);
				const account = safeshift(checkSafety2);
				const token = safeshift(checkSafety2);
				let _amt = undefined;
				try{
					_amt = new BigNumber(safeshift(checkSafety2));
					checkSafety2(parseInt(account) == NaN, "Invalid UserID!");
				} catch(e){
					if(amt){
						return;
					} else{
						try{
							//Will-throw guarantee
							fail("Invalid amount!");
							throw "";
						} catch(e){
							return;
						}
					}
				}
				const amount = _amt;
				_amt = undefined;
				
				const promise = BlockchainManager.sendSignedTransaction();
				promise.throw(async function(){
					//Just here to inhibit failures.
				});
				const confirmation = async function(n, receipt){
					if(n < 20 || !receipt){
						return;
					}
					if(receipt.status){
						safeQuery("LOCK TABLE Balances WRITE;", async function(){
							const selector = [" WHERE Coin = '", sqlescape(token), "' AND UserID = ", account, ";"].join("");
							safeQuery("SELECT Balance FROM Balances" + selector, async function(balance){
								let insert = false;
								try{
									if(balance.length == 0){
										balance = amount;
										insert = true;
									} else{
										checkSafety(balance.length == 1, "Corrupted balances database!");
										balance = balance[0];
										checkSafety(balance.Balance, "Corrupted balances database!");
										balance = balance.Balance;
										try{
											balance = (new BigNumber(balance)).add(amount).toString();
										} catch (e){
											fail("Unable to add BigNumbers!");
										}
									}
									
								} catch (e){
									return;
								}
								
								const exit = async function(){
									safeQuery("UNLOCK TABLES;", async function(){
										ret2("");
									});
								};
								
								if(insert){
									safeQuery(["INSERT INTO Balances (Coin, Balance, UserID) VALUES ('", sqlescape(token), "', '", sqlescape(balance), "', ", sqlescape(account), ");"].join(""), exit);
								} else{
									safeQuery(["UPDATE Balances SET Balance = '", sqlescape(balance), "'", selector].join(""), exit);
								}
								
								
							});
						});
					}
					
					promise.off('confirmation', confirmation);
					return;
				};
				promise.on('confirmation', confirmation);
				
				ret2("", true);
			}
		};
		
		const method = methods[params.pop()];
		if(method){
			useSQL(res, method);
		} else{
			res.write('{"error": "Invalid request method!"}');
			res.end();
		}
		
		
	});
	http.listen(12345);
	
	
	
	//if we get a SIGTERM, stop accepting new requests. Failure to
	//do this nearly ended with the catastrophic failure of the EUBI-bEUBI bridge.
	process.on('SIGINT', async function(){
		if(http){
			http.close();
			http = null;
			if(beforesqlavail){
				//It's safe to abort ungracefully here, since no requests should
				//be pending at this time
				process.exit();
			}
		}
	});
}