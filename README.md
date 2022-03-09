# OpenCEX: Open-source cryptocurrency exchange

## [Start trading now](https://exchange.polyeubitoken.com/)

As part of our transparency policy, we let customers view the source code of our cryptocurrency exchange.

## Roadmap

1. Proof-of-concept test launch 1
2. Proof-of-concept test launch 2
3. Main launch

## To-do list

### Phrase 1 - before test launch 1
~~1. Core features (accounts, trading, deposits, and withdrawals)~~

### Phrase 2 - before test launch 2
~~1. List partner tokens~~

~~2. Price charts~~

3. Add new account settings
4. Add new security features

### Phrase 3 - before main launch
1. Major code clean-ups
2. Security hardening

## FAQs

### Is this exchange safe
While OpenCEX is still experimental, the Jessie Lesbian Cryptocurrency Exchange is safe. We used the best cybersecurity practices in our exchange. We battle-tested our code in our isolated test servers, before uploading it in our production servers. If you want to, you can learn our cybersecurity best practices [here](https://www.coursera.org/learn/identifying-security-vulnerabilities).

![image](https://user-images.githubusercontent.com/55774978/155685203-be0589ec-9905-463c-9a18-531241000ece.png)

### About deposits and withdrawals

#### Why do I need to click on "finalize deposit"?
OpenCEX doesn't scan deposit addresses for balances like MintME.com and Highpay-Pool. Instead, clicking on "finalize deposit" reminds OpenCEX to check your deposit address for cryptocurrencies, and credit them to your account if they are found.

#### I got an "insufficent balance" error while depositing
Make sure that you deposit MintME/MATIC first, and then deposit your ERC-20 tokens. The OpenCEX ERC20 deposit mechanism uses a lot of blockchain gas, especially when used for the first time. We believe that funding the deposit address first with MintME/MATIC for gas is a bit risky, and used a deterministic address smart contract instead.

#### I got an "insufficent balance" error while withdrawing
Reduce your withdrawal and try again. Also, please note that the withdrawal amount you entered is BEFORE transaction fees are added, not AFTER transaction fees are added.

#### When will my deposit get credited to my account?
Deposits need at least 10 blockchain confirmations before they are credited to your account. This means 2 minutes and 10 seconds for MintME, and 20 seconds for Polygon.

#### My deposit is not getting credited to my account!
1. Look up your deposit address on the blockchain explorer
2. If nothing have been sent from the deposit address, wait a few minutes and click on "finalize deposit" again.
3. If it doesn't work, you can contact Jessie Lesbian at jessielesbian@eubitoken.com. She have database administrator privileges and can manually update your balance.

### Why do you have test launches?
Our testers need to test the exchange. Also, it's a chance for you to test it as well.

### What happens if theft of funds occour?
If less than 10% of all customer funds is lost, operations will be suspended until we got the vulnerability patched. Then, we will resume trading as usual. No funds will be debited from customer accounts. This may sound scary, but here's the truth. The loss of our hot wallet would reduce our reserve ratio to 90%, while banks are allowed to have reserve ratios as small as 1%, and Defi lending protocols have a reserve ratio of 20%.

If more than 10% of all customer funds is lost, we do the same as above, but we debit the loss to customer accounts proportionally and replace it with loss marker tokens until we can recover the lost funds. We may impose or increase fees to make back this loss. For example, if we lost 20% of all customer funds, then we will debit 20% from all customer accounts, and replace it with loss marker tokens.

If we lost customer funds to blockchain or smart contract bugs, we will permanently stop trading and deposits of the affected cryptocurrency. Then, we will debit the loss to customer accounts proportionally. Customers can then withdraw whatever is left on the exchange.

We periodically move funds between the exchange hot wallet and our cold wallet. We try to keep 90% of all customer funds in our cold wallet.

### What if I forgot my password?
If you forgot your password during the test launches, your funds are lost forever, but during the main launch, you can reset your password using one of your linked account recovery modes.

### What are your listing requirements?
1. Listing fee: 50 MATIC (not required for extremely serious and partner projects such as Bitcoin and PolyEUBI).
2. Verified contract source code (not required for tokens deployed using MintME.com).
3. Legitimate and serious project (no scamcoins, memecoins, or shitcoins).

Extremely serious cryptocurrencies, such as Ethereum, may be listed without contacting the team, free of charge.

### Why no trading fees?
Because we have advertisements, and trading fees are bad for liquidity.

### Can you explain the 3 order types?
1. Limit order: An order to buy or sell at a specific price or better. This is the only order type that comes with a minimum order size, and the only order type that is ever admitted to the order book on the Jessie Lesbian Cryptocurrency Exchange.
2. Immediate or cancel: An order to buy or sell that must be executed immediately. Any portion of an immediate or cancel order that cannot be filled immediately will be cancelled. Immediate or cancel orders are useful for arbitrage trades, and they have no minimum order size.
3. Fill or kill: An order to buy or sell that must be executed immediately in its entirety; otherwise, the entire order will be cancelled. The're is no minimum order size for fill or kill orders.

### How do you switch trading pairs?
You need to scroll down and click this arrow button
![image](https://user-images.githubusercontent.com/55774978/155685469-a8c8cadc-07a9-425f-8ac2-582f795679c8.png)

