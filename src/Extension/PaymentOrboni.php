<?php

/**
 * Orboni for J2Commerce: crypto payments (USDT, USDC, BTC, ETH and more)
 * straight to the store's own wallets.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */

namespace Orboni\Plugin\J2commerce\PaymentOrboni\Extension;

\defined('_JEXEC') or exit;

use J2Commerce\Component\J2commerce\Administrator\Helper\CartHelper;
use J2Commerce\Component\J2commerce\Administrator\Helper\CurrencyHelper;
use J2Commerce\Component\J2commerce\Administrator\Helper\J2CommerceHelper;
use J2Commerce\Component\J2commerce\Administrator\Helper\J2htmlHelper;
use J2Commerce\Component\J2commerce\Administrator\Helper\OrderStatusHelper;
use J2Commerce\Component\J2commerce\Administrator\Library\Plugins\Payment;
use J2Commerce\Component\J2commerce\Administrator\Library\Plugins\PluginLayoutTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Orboni\Plugin\J2commerce\PaymentOrboni\Api;
use Orboni\Plugin\J2commerce\PaymentOrboni\ApiException;
use Orboni\Plugin\J2commerce\PaymentOrboni\Currencies;
use Orboni\Plugin\J2commerce\PaymentOrboni\Signature;
use Orboni\Plugin\J2commerce\PaymentOrboni\State;
use Orboni\Plugin\J2commerce\PaymentOrboni\Sync;

/**
 * "Crypto" at J2Commerce's checkout. At the confirm step the buyer presses
 * the plugin's button: J2Commerce checks the order once more (the terms, the
 * cart, stock and coupons), the plugin makes an Orboni checkout session and
 * the buyer goes to its payment page, picks a coin and pays to the store's
 * own wallet. The order is marked paid by the signed webhook, by reading the
 * session back when the buyer returns, or by the cron, whichever comes first
 * ({@see Sync}). The cart is kept until then, so a buyer who leaves the
 * payment page can still pay another way.
 */
final class PaymentOrboni extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use PluginLayoutTrait;

    public const ELEMENT = 'payment_orboni';

    /**
     * Time a payment page must still have to be reused when the buyer presses
     * the button again: less, and they get a new one. A page Orboni still
     * calls open may be past its time (held open while a network cannot be
     * read).
     */
    private const REUSE_MIN_SECONDS = 300;

    /** Orders the cron reads per call. */
    private const CRON_BATCH = 50;

    private const LOG_CATEGORY = 'plg_j2commerce_payment_orboni';

    protected $autoloadLanguage = true;

    protected $_element = self::ELEMENT;

    protected $_type = 'j2commerce';

    private ?Payment $payment = null;

    private static bool $logger = false;

    public function __construct(
        private DispatcherInterface $pluginDispatcher,
        private array $pluginConfig,
        private Language $language,
        DatabaseInterface $db
    ) {
        parent::__construct($pluginDispatcher, $pluginConfig);
        $this->setDatabase($db);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onJ2CommerceGetPaymentPlugins' => 'onGetPaymentPlugins',
            'onJ2CommercePrePayment' => 'onPrePayment',
            'onJ2CommercePostPayment' => 'onPostPayment',
            'onAjaxPayment_orboni' => 'onAjax',
        ];
    }

    /**
     * Offered with a secret key saved, in a currency Orboni takes a price in,
     * and for a dollar price from $1 up.
     */
    public function onGetPaymentPlugins(Event $event): void
    {
        if ($this->secretKey() === '') {
            return;
        }
        $currency = strtoupper((string) CurrencyHelper::getCode());
        if (! Currencies::supports($currency)) {
            return;
        }
        $order = $event->getArguments()[0] ?? null;
        if ($currency === 'USD' && \is_object($order) && isset($order->order_total)) {
            $total = CurrencyHelper::gatewayAmount($order);
            if ($total > 0 && Currencies::minor($total, 'USD') < Currencies::MIN_USD_CENTS) {
                return;
            }
        }

        $result = $event->getArgument('result', []);
        $result[] = [
            'element' => self::ELEMENT,
            'name' => J2htmlHelper::translateKey((string) $this->params->get('display_name', 'PLG_J2COMMERCE_PAYMENT_ORBONI_DEFAULT_NAME')),
            'image' => (string) $this->params->get('display_image', ''),
        ];
        $event->setArgument('result', $result);
    }

    /** The confirm step: what the buyer will do, and the button that sends them to pay. */
    public function onPrePayment(Event $event): void
    {
        $args = $event->getArguments();
        if (($args[0] ?? '') !== self::ELEMENT) {
            return;
        }
        $data = (array) ($args[1] ?? []);
        $order = $this->orderTable();
        $order->load(['order_id' => (string) ($data['order_id'] ?? '')]);

        $vars = new \stdClass;
        $vars->order_id = (string) ($data['order_id'] ?? '');
        $vars->orderpayment_id = (int) ($data['orderpayment_id'] ?? 0);
        $vars->orderpayment_type = self::ELEMENT;
        $vars->display_name = J2htmlHelper::translateKey((string) $this->params->get('display_name', 'PLG_J2COMMERCE_PAYMENT_ORBONI_DEFAULT_NAME'));
        $vars->onbeforepayment_text = (string) $this->params->get('onbeforepayment', '');
        $vars->button_text = J2htmlHelper::translateKey((string) $this->params->get('button_text', 'PLG_J2COMMERCE_PAYMENT_ORBONI_BUTTON'));
        $vars->hash = $this->payment()->generateHash($order);

        $result = $event->getArgument('result', []);
        $result[] = $this->resolvePluginLayout('prepayment', ['vars' => $vars]);
        $event->setArgument('result', $result);
    }

    /**
     * The button's request (`paction=process`, through J2Commerce's
     * confirmPayment, which has checked the order first): the address of the
     * payment page as `redirect`, or a sentence as `error`.
     *
     * The answer always carries `error`, empty when there is none: J2Commerce
     * takes a JSON answer without it as an on-site payment that is complete,
     * and would empty the cart and send the order emails before anything is
     * paid.
     */
    public function onPostPayment(Event $event): void
    {
        $args = $event->getArguments();
        if (($args[0] ?? '') !== self::ELEMENT) {
            return;
        }
        if (Factory::getApplication()->getInput()->getString('paction', '') !== 'process') {
            return;
        }

        $answer = Session::checkToken() ? $this->process() : ['error' => Text::_('JINVALID_TOKEN')];
        $answer += ['error' => ''];

        $result = $event->getArgument('result', []);
        $result[] = (string) json_encode($answer);
        $event->setArgument('result', $result);
    }

    /**
     * `index.php?option=com_ajax&group=j2commerce&plugin=payment_orboni&format=raw&task=…`:
     * the buyer back from the payment page (`return`), Orboni's webhook
     * (`webhook`) and the cron (`cron&key=…`).
     */
    public function onAjax(Event $event): void
    {
        $task = Factory::getApplication()->getInput()->getCmd('task', '');
        if ($task === 'webhook') {
            $this->webhook();
        } elseif ($task === 'cron') {
            $this->cron();
        } elseif ($task === 'return') {
            $this->buyerReturn();
        }
        $this->answer(404, 'unknown_task');
    }

    /**
     * @return array{redirect?: string, error?: string}
     */
    private function process(): array
    {
        $unavailable = Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_ERROR_UNAVAILABLE');
        $orderId = trim(Factory::getApplication()->getInput()->getString('order_id', ''));
        $order = $this->orderTable();
        if ($orderId === '' || ! $order->load(['order_id' => $orderId])) {
            return ['error' => $unavailable];
        }
        if ((string) $order->orderpayment_type !== self::ELEMENT || ! $this->payment()->validateHash($order)) {
            return ['error' => $unavailable];
        }

        $result = $this->paymentPage((string) $order->order_id);
        if (isset($result['error'])) {
            (new Sync($this))->note((string) $order->order_id, Text::sprintf('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_NO_PAGE', $result['error']));

            return ['error' => $result['error']];
        }

        return ['redirect' => $result['url']];
    }

    /**
     * Where the buyer goes: `url` (the payment page, or the confirmation page
     * of an order already paid or on its way), or `error`, a sentence for them.
     *
     * @return array{url?: string, error?: string}
     */
    private function paymentPage(string $orderId): array
    {
        $unavailable = Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_ERROR_UNAVAILABLE');
        $secret = $this->secretKey();
        $state = State::get($orderId);
        if ($secret === '' || $state === null) {
            return ['error' => $unavailable];
        }
        $order = $state['order'];

        // Paid, or money on its way: nothing more to pay.
        if ($state['paid_session'] !== '' || OrderStatusHelper::getType((int) $order['order_state_id']) !== OrderStatusHelper::TYPE_NEW) {
            return ['url' => $this->confirmationUrl($order)];
        }

        [$amount, $currency] = $this->due($order);
        if (! Currencies::supports($currency)) {
            return ['error' => Text::sprintf('PLG_J2COMMERCE_PAYMENT_ORBONI_ERROR_CURRENCY', $currency)];
        }
        if ($amount <= 0) {
            return ['error' => $unavailable];
        }
        if ($currency === 'USD' && $amount < Currencies::MIN_USD_CENTS) {
            return ['error' => Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_ERROR_MINIMUM_USD')];
        }

        $api = new Api($secret);

        // Pressed again for the same amount: the page still open is reused,
        // unless it has run out or is about to.
        if ($state['session_id'] !== '' && (int) $state['amount'] === $amount && $state['currency'] === $currency) {
            try {
                $session = $api->getSession((string) $state['session_id']);
                if (self::reusable($session)) {
                    return ['url' => (string) $session['url']];
                }
            } catch (ApiException $e) {
                $this->log(\sprintf('Order %s: could not read session %s: %s', $orderId, $state['session_id'], $e->getMessage()), true);
            }
        }

        // Saved first: the return address carries it, and a retry must send the same body.
        $token = $state['token'] !== '' ? (string) $state['token'] : bin2hex(random_bytes(16));
        State::save($orderId, ['token' => $token]);

        // The key is kept while a try fails, so a session Orboni made for a try
        // whose answer was lost is the one the next try gets.
        $attempt = (int) $state['attempt'] + 1;
        $body = $this->sessionBody($order, $amount, $currency, $token);

        try {
            try {
                $session = $api->createSession($body, $this->idempotencyKey($order, $amount, $currency, $attempt));
            } catch (ApiException $e) {
                // That lost try was sent with other details (the buyer's language, an
                // edited item): Orboni refuses the key for them, and a new one is taken.
                if ($e->type !== 'idempotency_error' || $e->status !== 400) {
                    throw $e;
                }
                $attempt++;
                $session = $api->createSession($body, $this->idempotencyKey($order, $amount, $currency, $attempt));
            }
        } catch (ApiException $e) {
            $this->log(\sprintf('Order %s: Orboni refused a session (%s): %s', $orderId, $e->type, $e->getMessage()), true);

            // Below the least Orboni takes: under a network's minimum, or worth under $1 in another currency.
            if ($e->type === 'amount_too_small' || ($e->type === 'invalid_request_error' && $e->param === 'amount')) {
                return ['error' => Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_ERROR_MINIMUM')];
            }

            return ['error' => $unavailable];
        }

        if (! isset($session['id'], $session['url']) || ! \is_string($session['id']) || ! \is_string($session['url'])) {
            $this->log(\sprintf('Order %s: Orboni answered without a session id or url.', $orderId), true);

            return ['error' => $unavailable];
        }

        $expires = (int) ($session['expires_at'] ?? 0) > 0 ? (int) $session['expires_at'] : time() + 3600;
        $sessions = $state['sessions'];
        $sessions[] = $session['id'];
        State::save($orderId, [
            'session_id' => $session['id'],
            'url' => $session['url'],
            'sessions' => $sessions,
            'amount' => $amount,
            'currency' => $currency,
            'attempt' => $attempt,
            'expires_at' => $expires,
            'checked_at' => time() - Sync::RECHECK_SECONDS + 600,
        ]);
        $this->setTransaction($orderId, $session['id']);
        (new Sync($this))->note($orderId, Text::sprintf('PLG_J2COMMERCE_PAYMENT_ORBONI_NOTE_SENT', $session['id']));
        $this->log(\sprintf('Order %s: session %s for %s.', $orderId, $session['id'], Currencies::format($amount, $currency)));

        return ['url' => $session['url']];
    }

    /**
     * @param  array<string, mixed>  $order  {@see State::order()}
     * @return array<string, mixed>
     */
    private function sessionBody(array $order, int $amount, string $currency, string $token): array
    {
        $orderId = (string) $order['order_id'];
        $email = trim((string) $order['user_email']);
        $lang = $this->getApplication()->getLanguage()->getTag();

        $body = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => self::fit($this->items($orderId), 250),
            'details' => self::fit(Text::sprintf('PLG_J2COMMERCE_PAYMENT_ORBONI_ORDER_AT', $orderId, $this->storeName()), 500),
            'client_reference_id' => $orderId,
            'customer_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'locale' => $lang,
            'success_url' => $this->ajaxUrl('return').'&order_id='.rawurlencode($orderId).'&key='.rawurlencode($token),
            // Back to checkout: the order and the cart as they were, to pay again or another way.
            'cancel_url' => $this->checkoutUrl(),
            'metadata' => [
                'j2commerce_order_id' => $orderId,
                'j2commerce_site' => $this->siteKey(),
            ],
        ];

        return array_filter($body, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The buyer is back from the payment page: the session is read once, so
     * the order is right even before the webhook comes (or without one). Paid
     * or on its way: J2Commerce's confirmation page, which says where the
     * payment stands. Not yet: back to checkout with a sentence, the cart and
     * the order as they were, to pay on the same page or another way.
     */
    private function buyerReturn(): void
    {
        $app = $this->getApplication();
        $orderId = $app->getInput()->getString('order_id', '');
        $key = $app->getInput()->getString('key', '');
        $state = $orderId !== '' ? State::get($orderId) : null;
        if ($state === null || $state['token'] === '' || ! hash_equals((string) $state['token'], $key)) {
            $app->enqueueMessage(Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_RETURN_UNKNOWN'), 'warning');
            $app->redirect(Uri::root());

            return;
        }

        $session = null;
        $secret = $this->secretKey();
        if ($state['paid_session'] === '' && $state['session_id'] !== '' && $secret !== '') {
            try {
                $session = (new Api($secret))->getSession((string) $state['session_id']);
                (new Sync($this))->applyLocked($orderId, $session);
                $this->remember($orderId, $session);
            } catch (ApiException $e) {
                $this->log(\sprintf('Order %s: could not read session %s: %s', $orderId, $state['session_id'], $e->getMessage()), true);
            }
            $state = State::get($orderId) ?? $state;
        }

        $order = $state['order'];
        $type = OrderStatusHelper::getType((int) $order['order_state_id']);
        if ($state['paid_session'] !== '' || $type !== OrderStatusHelper::TYPE_NEW) {
            // Placed: this checkout is over, as J2Commerce ends its own.
            if ((string) $app->getUserState('j2commerce.order_id', '') === $orderId) {
                $this->endCheckout($orderId);
            }
            $app->setUserState('j2commerce.confirmation_plugin_html', $this->confirmationHtml($state));
            $app->redirect($this->confirmationUrl($order));

            return;
        }

        $payment = (string) ($session['payment_status'] ?? '');
        $status = (string) ($session['status'] ?? '');
        $message = match (true) {
            $payment === 'partially_paid' => Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_RETURN_PARTLY'),
            $status === 'expired' => Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_RETURN_CLOSED'),
            default => Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_RETURN_OPEN'),
        };
        $app->enqueueMessage($message, 'warning');
        $app->redirect($this->checkoutUrl());
    }

    /**
     * The signature is checked with the signing secret, the event matched to
     * its order, and the session read back from Orboni before anything
     * changes — the order follows the session as it is now, never an old
     * event's copy of it.
     *
     * Answers: 200 for an event taken or one that is not this store's (Orboni
     * sends every event of a project to each of its endpoints, and a non-2xx
     * would be sent again for days); 401 for a bad signature; 400 for a body
     * that is no event; 503 while the store cannot decide yet (no signing
     * secret saved, Orboni not answering, the order busy) — Orboni retries.
     */
    private function webhook(): void
    {
        $secret = $this->webhookSecret();
        if ($secret === '' || $this->secretKey() === '') {
            $this->answer(503, 'not_configured');
        }

        $body = (string) file_get_contents('php://input');
        $header = $this->getApplication()->getInput()->server->getString('HTTP_WEBHOOK_SIGNATURE', '');
        if (! Signature::verify($secret, $body, $header)) {
            $this->log('Webhook refused: the signature does not match the signing secret.', true);
            $this->answer(401, 'bad_signature');
        }

        $event = json_decode($body, true);
        if (! \is_array($event) || ! isset($event['type']) || ! \is_string($event['type'])
            || ! isset($event['data']['object']) || ! \is_array($event['data']['object'])) {
            $this->answer(400, 'bad_event');
        }

        $type = $event['type'];
        $object = $event['data']['object'];
        if (! str_starts_with($type, 'checkout.session.')) {
            $this->answer(200, 'ignored');
        }

        $id = (string) ($object['id'] ?? '');
        $orderId = (string) ($object['client_reference_id'] ?? '');
        $metadata = isset($object['metadata']) && \is_array($object['metadata']) ? $object['metadata'] : [];
        if (preg_match(Api::SESSION_ID, $id) !== 1 || $orderId === '' || (string) ($metadata['j2commerce_site'] ?? '') !== $this->siteKey()) {
            // Another store's, or a payment link's: not ours to act on.
            $this->answer(200, 'ignored');
        }

        $state = State::get($orderId);
        if ($state === null || ! \in_array($id, $state['sessions'], true)) {
            $this->log(\sprintf('Webhook %s for session %s: no order of this store has it.', $type, $id), true);
            $this->answer(200, 'ignored');
        }

        try {
            $session = (new Api($this->secretKey()))->getSession($id);
        } catch (ApiException $e) {
            $this->log(\sprintf('Webhook %s, order %s: could not read session %s back: %s', $type, $orderId, $id, $e->getMessage()), true);
            $this->answer(503, 'retry_later');
        }

        $taken = (new Sync($this))->applyLocked($orderId, $session, $type, (string) ($event['id'] ?? ''));
        if ($taken === null) {
            $this->answer(503, 'busy');
        }
        $this->remember($orderId, $session);

        $this->log(\sprintf('Webhook %s, order %s: %s.', $type, $orderId, $taken ? 'applied' : 'already taken'));
        $this->answer(200, $taken ? 'received' : 'duplicate');
    }

    /**
     * The cron address (the settings show it): reads back the payment pages
     * still open, in case a webhook was lost. Its key is made from the secret
     * key, so nobody without it can make the store call Orboni.
     */
    private function cron(): void
    {
        $secret = $this->secretKey();
        $expected = Signature::cronKey($secret);
        $key = $this->getApplication()->getInput()->getString('key', '');
        if ($expected === '' || ! hash_equals($expected, $key)) {
            $this->answer(403, 'bad_key');
        }

        $api = new Api($secret);
        $sync = new Sync($this);
        $read = 0;
        foreach (State::open(self::CRON_BATCH) as $orderId) {
            $state = State::get($orderId);
            if ($state === null || $state['session_id'] === '') {
                State::save($orderId, ['checked_at' => time()]);

                continue;
            }
            State::save($orderId, ['checked_at' => time()]);
            $read++;

            try {
                $session = $api->getSession((string) $state['session_id']);
            } catch (ApiException $e) {
                $this->log(\sprintf('Order %s: the cron could not read session %s: %s', $orderId, $state['session_id'], $e->getMessage()), true);

                continue;
            }

            $sync->applyLocked($orderId, $session);
            $this->remember($orderId, $session);
        }

        $this->answer(200, 'read '.$read);
    }

    /**
     * Paid: what J2Commerce does after any payment — its after-payment
     * listeners, the order emails — and the cart emptied, wherever the buyer
     * is. Each step on its own: a failing one must not undo a paid order.
     */
    public function afterPaid(object $orderTable): void
    {
        $orderId = (string) $orderTable->order_id;

        try {
            J2CommerceHelper::plugin()->event('AfterPayment', [$orderTable]);
        } catch (\Throwable $e) {
            $this->log(\sprintf('Order %s: an after-payment listener failed: %s', $orderId, $e->getMessage()), true);
        }

        try {
            $this->getApplication()->bootComponent('com_j2commerce')->getMVCFactory()
                ->createModel('Order', 'Administrator')
                ->sendOrderNotification($orderId, true, true);
        } catch (\Throwable $e) {
            $this->log(\sprintf('Order %s: the order emails were not sent: %s', $orderId, $e->getMessage()), true);
        }

        try {
            CartHelper::emptyCart($orderId);
        } catch (\Throwable $e) {
            $this->log(\sprintf('Order %s: the cart was not emptied: %s', $orderId, $e->getMessage()), true);
        }
    }

    /** J2Commerce's order table, empty. */
    public function orderTable(): object
    {
        return $this->getApplication()->bootComponent('com_j2commerce')->getMVCFactory()->createTable('Order', 'Administrator');
    }

    /**
     * The status a paid order gets: the setting, else the one status of the
     * Complete kind, else the core Confirmed. Ids differ between stores (a
     * store moved from J2Store keeps its own), so none is written here.
     */
    public function statusPaid(): int
    {
        return $this->status('paid_status', OrderStatusHelper::TYPE_COMPLETE, 'J2COMMERCE_CONFIRMED');
    }

    /** The status of an order whose money is on its way or held: the setting, else Pending, the same way. */
    public function statusWaiting(): int
    {
        return $this->status('waiting_status', OrderStatusHelper::TYPE_OPEN, 'J2COMMERCE_PENDING');
    }

    /** 0: none could be told — the order keeps its status and gets the note alone. */
    private function status(string $setting, string $type, string $coreName): int
    {
        $id = (int) $this->params->get($setting, 0);

        return $id > 0 ? $id : OrderStatusHelper::idOfType($type, $coreName);
    }

    /**
     * What the order costs, in the currency the buyer saw: the smallest unit
     * and the code, `[1250, 'EUR']`. J2Commerce keeps totals in the store's
     * currency with the rate of the buyer's.
     *
     * @param  array<string, mixed>  $order  {@see State::order()}
     * @return array{0: int, 1: string}
     */
    public function due(array $order): array
    {
        $currency = strtoupper(trim((string) $order['currency_code']));

        return [Currencies::minor(CurrencyHelper::gatewayAmount((object) $order), $currency), $currency];
    }

    /**
     * This store among others on the same Orboni project: in the idempotency
     * key and the session's metadata, so two stores' orders never meet. Made
     * from the site's own secret, the same whatever address it is reached at.
     */
    public function siteKey(): string
    {
        return substr(hash_hmac('sha256', 'orboni-j2commerce-site', (string) $this->getApplication()->get('secret')), 0, 12);
    }

    public function log(string $message, bool $error = false): void
    {
        if (! $error && ! $this->params->get('debug', 0)) {
            return;
        }
        if (! self::$logger) {
            Log::addLogger(['text_file' => 'plg_j2commerce_payment_orboni.php'], Log::ALL, [self::LOG_CATEGORY]);
            self::$logger = true;
        }
        Log::add($message, $error ? Log::ERROR : Log::INFO, self::LOG_CATEGORY);
    }

    /** The address of one of the plugin's tasks. */
    public static function ajaxUrl(string $task): string
    {
        return rtrim(Uri::root(), '/').'/index.php?option=com_ajax&group=j2commerce&plugin=payment_orboni&format=raw&task='.$task;
    }

    private function secretKey(): string
    {
        return trim((string) $this->params->get('secret_key', ''));
    }

    private function webhookSecret(): string
    {
        return trim((string) $this->params->get('webhook_secret', ''));
    }

    /** The current page's session on the order, where J2Commerce's order view shows a transaction. */
    private function setTransaction(string $orderId, string $sessionId): void
    {
        $db = $this->getDatabase();
        $pending = 'Pending';
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__j2commerce_orders'))
            ->set($db->quoteName('transaction_id').' = :tid')
            ->set($db->quoteName('transaction_status').' = :status')
            ->where($db->quoteName('order_id').' = :order')
            ->bind(':tid', $sessionId)
            ->bind(':status', $pending)
            ->bind(':order', $orderId);
        $db->setQuery($query)->execute();
    }

    /** What J2Commerce clears once a checkout has placed its order. */
    private function endCheckout(string $orderId): void
    {
        $app = $this->getApplication();
        $session = $app->getSession();
        foreach (['shipping_method', 'shipping_methods', 'payment_method', 'payment_methods', 'payment_values',
            'payment_custom_fields', 'guest', 'guest_shipping', 'customer_note', 'order_fees'] as $name) {
            $session->clear($name, 'j2commerce');
        }
        $app->setUserState('j2commerce.order_id', null);
        $app->setUserState('j2commerce.orderpayment_id', null);

        try {
            CartHelper::emptyCart($orderId);
        } catch (\Throwable $e) {
            $this->log(\sprintf('Order %s: the cart was not emptied: %s', $orderId, $e->getMessage()), true);
        }
    }

    /**
     * Where the payment stands, for J2Commerce's confirmation page: nothing
     * once it is paid (J2Commerce says so itself), a sentence while it is on
     * its way or held.
     *
     * @param  array<string, mixed>  $state
     */
    private function confirmationHtml(array $state): string
    {
        if ($state['paid_session'] !== '') {
            return '';
        }
        $parts = explode(':', (string) $state['state']);
        $message = ($parts[2] ?? '') === 'held'
            ? Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_RETURN_HELD')
            : Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_RETURN_PROCESSING');

        return '<div class="alert alert-info">'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</div>';
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function confirmationUrl(array $order): string
    {
        return Route::_('index.php?option=com_j2commerce&view=confirmation&order_id='.rawurlencode((string) $order['order_id'])
            .'&token='.rawurlencode((string) $order['token']), false, Route::TLS_IGNORE, true);
    }

    private function checkoutUrl(): string
    {
        return Route::_('index.php?option=com_j2commerce&view=checkout', false, Route::TLS_IGNORE, true);
    }

    /**
     * The page's address and close time, kept for a later press of the button.
     *
     * @param  array<string, mixed>  $session
     */
    private function remember(string $orderId, array $session): void
    {
        if (isset($session['url'], $session['expires_at']) && \is_string($session['url'])) {
            $state = State::get($orderId);
            if ($state !== null && $state['session_id'] === (string) ($session['id'] ?? '')) {
                State::save($orderId, ['url' => $session['url'], 'expires_at' => (int) $session['expires_at']]);
            }
        }
    }

    private function payment(): Payment
    {
        if ($this->payment === null) {
            $this->payment = new Payment($this->pluginDispatcher, $this->pluginConfig);
            $this->payment->_element = $this->_name;
        }

        return $this->payment;
    }

    private function storeName(): string
    {
        return (string) $this->getApplication()->get('sitename');
    }

    /**
     * The store, the order, the amount and the try. The order's own token is
     * in it too: a store restored from a backup numbers its orders again, and
     * Orboni keeps a key a day.
     *
     * @param  array<string, mixed>  $order
     */
    private function idempotencyKey(array $order, int $amount, string $currency, int $attempt): string
    {
        $instance = substr(hash('sha256', (string) $order['token'].'|'.(string) $order['created_on']), 0, 8);

        return \sprintf('j2c-%s-%s-%s-%d%s-%d', $this->siteKey(), substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $order['order_id']), 0, 40), $instance, $amount, strtolower($currency), $attempt);
    }

    /**
     * A session the buyer can still pay on: open, with its page, and more
     * than REUSE_MIN_SECONDS before it runs out.
     *
     * @param  array<string, mixed>  $session
     */
    private static function reusable(array $session): bool
    {
        return ($session['status'] ?? '') === 'open' && \is_string($session['url'] ?? null)
            && (int) ($session['expires_at'] ?? 0) > time() + self::REUSE_MIN_SECONDS;
    }

    /** "Blue mug × 2, Green tea" — what the payment page shows as the purchase. */
    private function items(string $orderId): string
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['orderitem_name', 'orderitem_quantity']))
            ->from($db->quoteName('#__j2commerce_orderitems'))
            ->where($db->quoteName('order_id').' = :order')
            ->order($db->quoteName('j2commerce_orderitem_id').' ASC')
            ->bind(':order', $orderId);
        $names = [];
        foreach ((array) $db->setQuery($query)->loadObjectList() as $item) {
            $name = trim(html_entity_decode(strip_tags((string) $item->orderitem_name), ENT_QUOTES, 'UTF-8'));
            $quantity = (int) $item->orderitem_quantity;
            if ($name !== '' && $quantity > 0) {
                $names[] = $quantity > 1 ? $name.' × '.$quantity : $name;
            }
        }

        return implode(', ', $names);
    }

    /** At most `$max` characters, cut at a word with an ellipsis. */
    private static function fit(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,.;:').'…';
    }

    private function answer(int $code, string $result): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['result' => $result]);
        $this->getApplication()->close();
        exit;
    }
}
