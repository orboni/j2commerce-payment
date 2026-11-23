# Orboni for J2Commerce

Crypto payments for J2Commerce through [Orboni](https://orboni.com): buyers pay with USDT, USDC, BTC, ETH and other coins on Orboni's payment page, and the money goes straight to your own wallets. Orboni never holds it.

- Joomla 6 with J2Commerce 6; PHP 8.3 or later, MySQL or MariaDB.
- Prices in USD, EUR, GBP and 34 other currencies; Orboni turns them into coins at the rate of the day.
- The order is marked paid by a signed webhook, when the buyer comes back to your store, or by the cron address, whichever comes first.

## Install

1. Download `plg_j2commerce_payment_orboni.zip` from the [latest release](https://github.com/orboni/j2commerce-payment/releases/latest).
2. In Joomla, go to **System → Install → Extensions** and upload it. Joomla switches the plugin on by itself.
3. Open **J2Commerce → Payment methods → Orboni for J2Commerce**.
4. Paste your **Secret key** from the Orboni dashboard (Developers → API keys): `sk_test_…` to try it, `sk_live_…` for real payments. Save.
5. In the Orboni dashboard, under **Developers → Webhooks**, add an endpoint with the **Webhook address** the settings show and the `checkout.session.*` events, and paste its signing secret (`whsec_…`) into **Webhook signing secret**. Save.
6. Recommended: add the **Cron address** the settings show under **System → Scheduled Tasks → New → GET Request**, every 15 minutes. It reads back payments still on their way in case a webhook was lost.

Then place an order with it, and on its payment page, signed in to the Orboni dashboard, press **Simulate payment**: the order is confirmed. Swap in your live key and the live endpoint's secret when you are ready.

## How an order moves

- At the confirm step the buyer presses **Pay with crypto**: J2Commerce checks the order as for any payment, and the buyer goes to Orboni's payment page. The order stays **New** and the cart is kept, so a buyer who leaves the page can pay another way.
- Paid in full and final on the chain: **Confirmed**. J2Commerce sends its order emails and empties the cart.
- On the chain but not final yet, or held for your review in the Orboni dashboard: **Pending**, with a line in the order's history.
- Part paid, or the page ran out unpaid: a line in the history; the order stays as it was.
- Money that paid an order left the chain (a reorganisation): back to **Pending**, with a line saying not to ship.

Both statuses can be changed in the settings. Settings, keys and secrets are kept in Joomla's database; the secrets are never shown again once saved.

## Support

support@orboni.com · [orboni.com/docs/j2commerce](https://orboni.com/docs/j2commerce)

## License

GNU General Public License version 2 or later (see `LICENSE`).
