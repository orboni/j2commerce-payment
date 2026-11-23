<?php

/**
 * Orboni for J2Commerce.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */

namespace Orboni\Plugin\J2commerce\PaymentOrboni;

\defined('_JEXEC') or exit;

use J2Commerce\Component\J2commerce\Administrator\Helper\OrderHistoryHelper;
use J2Commerce\Component\J2commerce\Administrator\Helper\OrderStatusHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Orboni\Plugin\J2commerce\PaymentOrboni\Extension\PaymentOrboni;

/**
 * An Orboni checkout session, read back, onto its J2Commerce order. The one
 * place an order changes for a payment: the webhook, the buyer's return and
 * the cron all come here with the session as Orboni shows it now, so the
 * order follows the payment whatever arrives first, twice or out of order.
 *
 * - complete + paid → the "paid" status (Confirmed by default), J2Commerce's
 *   after-payment step, its order emails, and the cart emptied;
 * - on its way (processing) or held for the merchant's review (held) → the
 *   "waiting" status (Pending by default) and a line in the order's history;
 * - short of the amount (partially_paid), or expired unpaid → a line;
 * - a paid order goes back to waiting only when Orboni says money that paid
 *   it left the chain (`payment_reversed`); nothing else downgrades it.
 */
class Sync
{
    public const REVERSED = 'checkout.session.payment_reversed';

    public const EXTRA = 'checkout.session.extra_received';

    /** Seconds after a read before the cron reads the same order again. */
    public const RECHECK_SECONDS = 3000;

    /** Webhook events remembered per order, so a retry or a replay adds nothing twice. */
    public const EVENTS_KEPT = 30;

    /** Orboni's network keys as people write them; a network added later reads as its key. */
    public const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    public function __construct(private PaymentOrboni $plugin) {}

    /**
     * {@see apply()} under a lock, with the order and its state read fresh:
     * the webhook and the buyer's return can land in the same second.
     *
     * @param  array<string, mixed>  $session
     * @return bool|null true applied, false an event already taken (or no such order), null the order is busy
     */
    public function applyLocked(string $orderId, array $session, string $event = '', string $eventId = ''): ?bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $locks = stripos((string) $db->getServerType(), 'mysql') !== false;
        $lock = $db->quote('orboni_j2c_'.substr(hash('sha256', $db->getPrefix().$orderId), 0, 40));
        if ($locks && (int) $db->setQuery('SELECT GET_LOCK('.$lock.', 5)')->loadResult() !== 1) {
            return null;
        }

        try {
            $state = State::get($orderId);
            if ($state === null) {
                return false;
            }

            if ($eventId !== '') {
                if (\in_array($eventId, $state['events'], true)) {
                    return false;
                }
                $state['events'][] = $eventId;
                State::save($orderId, ['events' => \array_slice($state['events'], -self::EVENTS_KEPT)]);
            }

            $this->apply($orderId, $state, $session, $event);

            return true;
        } finally {
            if ($locks) {
                $db->setQuery('SELECT RELEASE_LOCK('.$lock.')')->execute();
            }
        }
    }

    /**
     * Made for this order by this store: one of the order's own pages, its
     * number as the reference, this site's key in the metadata.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $session
     */
    public function belongs(string $orderId, array $state, array $session): bool
    {
        $id = (string) ($session['id'] ?? '');
        $metadata = isset($session['metadata']) && \is_array($session['metadata']) ? $session['metadata'] : [];

        return $id !== ''
            && \in_array($id, $state['sessions'], true)
            && (string) ($session['client_reference_id'] ?? '') === $orderId
            && (string) ($metadata['j2commerce_site'] ?? '') === $this->plugin->siteKey();
    }

    /**
     * @param  array<string, mixed>  $state  {@see State::get()}
     * @param  array<string, mixed>  $session  Orboni's checkout session object
     * @param  string  $event  the webhook's type, when a webhook brought it
     */
    private function apply(string $orderId, array $state, array $session, string $event): void
    {
        $id = (string) ($session['id'] ?? '');
        if (! $this->belongs($orderId, $state, $session)) {
            $this->plugin->log(\sprintf('Order %s: session %s is not this order\'s; left alone.', $orderId, $id), true);

            return;
        }

        $status = (string) ($session['status'] ?? '');
        $payment = (string) ($session['payment_status'] ?? '');
        $seen = $id.':'.$status.':'.$payment;
        $paidHere = $status === 'complete' && $payment === 'paid';

        // The buyer went back and chose another way to pay for the same order.
        if ((string) $state['order']['orderpayment_type'] !== PaymentOrboni::ELEMENT) {
            if ($paidHere && $state['state'] !== $seen) {
                State::save($orderId, ['state' => $seen, 'finished' => true]);
                $this->note($orderId, Text::sprintf('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_OTHER_METHOD', $id, Currencies::money($session, 'amount_received')));
                $this->plugin->log(\sprintf('Order %s: paid by session %s, but the order now uses %s.', $orderId, $id, $state['order']['orderpayment_type']), true);
            }

            return;
        }

        if ($state['paid_session'] !== '') {
            $this->afterPaid($orderId, $state, $session, $event, $paidHere, $seen);

            return;
        }

        if ($state['state'] === $seen) {
            return;
        }
        State::save($orderId, ['state' => $seen]);

        if ($paidHere) {
            $this->complete($orderId, $state, $session);

            return;
        }

        switch ($payment) {
            case 'processing':
                $this->wait($orderId, $state, Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_PROCESSING'));

                return;

            case 'held':
                $this->wait($orderId, $state, Text::sprintf(
                    'PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_HELD',
                    (string) ($session['review_reason'] ?? '—')
                ));

                return;

            case 'partially_paid':
                $this->note($orderId, Text::sprintf(
                    $status === 'expired' ? 'PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_PARTLY_CLOSED' : 'PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_PARTLY',
                    Currencies::money($session, 'amount_received'),
                    Currencies::money($session, 'amount_due')
                ));

                return;
        }

        // A newer page (the buyer confirmed again) still counts; the order itself stays as it is.
        if ($status === 'expired' && $payment === 'unpaid' && $state['session_id'] === $id) {
            $this->note($orderId, Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_EXPIRED'));
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $session
     */
    private function complete(string $orderId, array $state, array $session): void
    {
        $asked = (int) ($session['amount_total'] ?? 0);
        $askedIn = strtoupper((string) ($session['currency'] ?? ''));
        [$due, $currency] = $this->plugin->due($state['order']);

        // The order was edited to cost more (or another currency) after the buyer was sent to pay.
        if ($askedIn !== $currency || $asked < $due) {
            $this->note($orderId, Text::sprintf(
                'PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_TOTAL_CHANGED',
                Currencies::format($asked, $askedIn),
                Currencies::format($due, $currency)
            ));

            return;
        }

        State::save($orderId, ['paid_session' => (string) $session['id'], 'finished' => true]);
        $this->recordCharge($state['order'], (string) $session['id'], $asked, $askedIn);
        $stored = $this->setStatus($orderId, $this->plugin->statusPaid(), $this->paidNote($session), true, [
            'transaction_id' => (string) $session['id'],
            'transaction_status' => 'Completed',
            'transaction_details' => (string) json_encode($this->details($session)),
        ]);
        if ($stored === null) {
            // Not stored: taken back, so the next read (the webhook's retry, the cron) tries again.
            State::save($orderId, ['paid_session' => '', 'finished' => false, 'state' => '']);

            return;
        }
        $this->plugin->log(\sprintf('Order %s: paid by session %s.', $orderId, $session['id']));
        $this->plugin->afterPaid($stored);
    }

    /**
     * The payment in J2Commerce's ledger of the order (6.4 and later), before
     * the status: from 6.7 a store may hold a Confirmed status back until a
     * payment is recorded, and the order view shows it as paid. One row per
     * session — the ledger keeps a session's charge once, however often it
     * is read. A reversal is left out of it: that is no refund, and the
     * order's history says what happened.
     *
     * @param  array<string, mixed>  $order
     */
    private function recordCharge(array $order, string $sessionId, int $amount, string $currency): void
    {
        $ledger = 'J2Commerce\\Component\\J2commerce\\Administrator\\Helper\\OrderTransactionHelper';
        if (! class_exists($ledger)) {
            return;
        }

        try {
            $ledger::addCharge((int) $order['j2commerce_order_id'], PaymentOrboni::ELEMENT, $sessionId, $amount / 10 ** Currencies::decimals($currency), $currency);
        } catch (\Throwable $e) {
            $this->plugin->log(\sprintf('Order %s: the payment was not recorded in the ledger: %s', $order['order_id'], $e->getMessage()), true);
        }
    }

    /**
     * Money on its way or held: the order moves from New to the waiting
     * status, so J2Commerce counts it as placed; one already further along
     * gets the line only.
     *
     * @param  array<string, mixed>  $state
     */
    private function wait(string $orderId, array $state, string $comment): void
    {
        $type = OrderStatusHelper::getType((int) $state['order']['order_state_id']);
        if ($type === OrderStatusHelper::TYPE_NEW) {
            $this->setStatus($orderId, $this->plugin->statusWaiting(), $comment, false, ['transaction_status' => 'Pending']);

            return;
        }
        $this->note($orderId, $comment);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $session
     */
    private function afterPaid(string $orderId, array $state, array $session, string $event, bool $paidHere, string $seen): void
    {
        $id = (string) $session['id'];

        if ($state['paid_session'] === $id) {
            if ($event === self::REVERSED && ! $paidHere) {
                // Not paid any more: the session paid in full again completes the order again.
                State::save($orderId, ['paid_session' => '', 'state' => $seen, 'finished' => false]);
                $this->setStatus($orderId, $this->plugin->statusWaiting(), Text::sprintf(
                    'PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_REVERSED',
                    Currencies::money($session, 'amount_received')
                ), false, ['transaction_status' => 'Pending']);
                $this->plugin->log(\sprintf('Order %s: payment reversed on session %s.', $orderId, $id), true);
            } elseif ($event === self::EXTRA) {
                $this->note($orderId, Text::sprintf('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_EXTRA', Currencies::money($session, 'amount_received')));
            }

            return;
        }

        // Another of the order's pages paid too: the buyer paid twice.
        if ($paidHere && $state['state'] !== $seen) {
            State::save($orderId, ['state' => $seen]);
            $this->note($orderId, Text::sprintf('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_PAID_TWICE', $id, Currencies::money($session, 'amount_received')));
            $this->plugin->log(\sprintf('Order %s: paid twice, again by session %s.', $orderId, $id), true);
        }
    }

    /**
     * A new status through J2Commerce's own order table, so its status
     * change runs as for any payment (stock, the history, its listeners),
     * with what happened in the order's history. The stored order, or null.
     *
     * @param  array<string, string>  $transaction
     */
    private function setStatus(string $orderId, int $stateId, string $comment, bool $notify, array $transaction = []): ?object
    {
        $table = $this->plugin->orderTable();
        if (! $table->load(['order_id' => $orderId])) {
            $this->plugin->log(\sprintf('Order %s: not found to update.', $orderId), true);

            return null;
        }
        if ($stateId > 0) {
            $table->order_state_id = $stateId;
        }
        foreach ($transaction as $field => $value) {
            $table->$field = $value;
        }
        if (! $table->store()) {
            $this->plugin->log(\sprintf('Order %s: J2Commerce did not store it: %s', $orderId, $table->getError()), true);

            return null;
        }

        try {
            OrderHistoryHelper::add(orderId: $orderId, comment: $comment, orderStateId: (int) $table->order_state_id, notifyCustomer: $notify);
        } catch (\Throwable $e) {
            $this->plugin->log(\sprintf('Order %s: the history line was not written: %s', $orderId, $e->getMessage()), true);
        }

        return $table;
    }

    /** A line in the order's history under its current status, for the store only. */
    public function note(string $orderId, string $comment): void
    {
        try {
            OrderHistoryHelper::add(orderId: $orderId, comment: $comment);
        } catch (\Throwable $e) {
            $this->plugin->log(\sprintf('Order %s: the history line was not written: %s', $orderId, $e->getMessage()), true);
        }
    }

    /**
     * What the order's transaction details show: the session, what was paid
     * and in what, test or live.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function details(array $session): array
    {
        $payment = isset($session['payment']) && \is_array($session['payment']) ? $session['payment'] : [];

        return array_filter([
            'provider' => 'Orboni',
            'session' => (string) $session['id'],
            'amount_received' => Currencies::money($session, 'amount_received'),
            'coin' => isset($payment['coin']) ? (string) $payment['coin'] : null,
            'network' => isset($payment['chain']) ? $this->chainName((string) $payment['chain']) : null,
            'coin_amount' => isset($payment['amount']) ? (string) $payment['amount'] : null,
            'test' => empty($session['livemode']) ? true : null,
            'paid_at' => gmdate('Y-m-d H:i:s').' UTC',
        ], static fn ($value) => $value !== null);
    }

    /**
     * "Paid with 25.00 USDT on TRON (25.00 USD) through Orboni, session cs_live_…."
     *
     * @param  array<string, mixed>  $session
     */
    private function paidNote(array $session): string
    {
        $payment = isset($session['payment']) && \is_array($session['payment']) ? $session['payment'] : [];
        $test = empty($session['livemode']) ? ' '.Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_TEST') : '';
        $late = ! empty($session['paid_late']) ? ' '.Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_LATE') : '';

        if (isset($payment['coin'], $payment['amount'])) {
            return Text::sprintf(
                'PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_PAID_WITH',
                (string) $payment['amount'],
                (string) $payment['coin'],
                $this->chainName((string) ($payment['chain'] ?? '')),
                Currencies::money($session, 'amount_received'),
                (string) $session['id']
            ).$test.$late;
        }

        return Text::sprintf('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_PAID', Currencies::money($session, 'amount_received'), (string) $session['id']).$test.$late;
    }

    private function chainName(string $chain): string
    {
        return self::CHAIN_NAMES[$chain] ?? ucfirst($chain);
    }
}
