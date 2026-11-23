<?php

/**
 * Orboni for J2Commerce.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */

namespace Orboni\Plugin\J2commerce\PaymentOrboni;

\defined('_JEXEC') or exit;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * What the plugin keeps about an order, in its own table
 * (`#__orboni_j2commerce`, one row per J2Commerce order number): the current
 * payment page and every earlier one (a buyer may still pay an older page),
 * what was last applied, the webhook events taken, the session that paid the
 * order, and the buyer's key back to the store. Not in the order's own
 * `order_params`: J2Commerce writes the whole order row again when the
 * buyer goes back and confirms, and a page they had already opened would
 * then lead nowhere.
 */
class State
{
    public const DEFAULTS = [
        'token' => '', 'session_id' => '', 'url' => '', 'sessions' => [], 'amount' => 0, 'currency' => '', 'attempt' => 0,
        'state' => '', 'paid_session' => '', 'events' => [], 'finished' => false,
    ];

    /** The order's fields the plugin reads, from `#__j2commerce_orders`. */
    private const ORDER_FIELDS = [
        'j2commerce_order_id', 'order_id', 'order_state_id', 'orderpayment_type', 'order_total', 'order_params',
        'currency_code', 'currency_value', 'user_email', 'user_id', 'token', 'cart_id', 'created_on',
    ];

    /**
     * The plugin's state for an order, with the order's own fields under
     * `order` — null for an order that does not exist.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $orderId): ?array
    {
        $order = self::order($orderId);
        if ($order === null) {
            return null;
        }

        $state = self::DEFAULTS;
        $state['expires_at'] = 0;
        $state['checked_at'] = 0;
        $row = self::row($orderId);
        if ($row !== null) {
            $data = json_decode((string) $row['data'], true);
            foreach (self::DEFAULTS as $key => $default) {
                if (\is_array($data) && \array_key_exists($key, $data)) {
                    $state[$key] = \is_array($default) ? (array) $data[$key] : $data[$key];
                }
            }
            $state['expires_at'] = (int) $row['expires_at'];
            $state['checked_at'] = (int) $row['checked_at'];
        }
        $state['order'] = $order;

        return $state;
    }

    /**
     * @param  array<string, mixed>  $fields  names of {@see DEFAULTS}, and `expires_at` / `checked_at`
     */
    public static function save(string $orderId, array $fields): void
    {
        $db = self::db();
        $row = self::row($orderId);
        $data = $row !== null ? json_decode((string) $row['data'], true) : [];
        $data = \is_array($data) ? $data : [];
        $expires = $row !== null ? (int) $row['expires_at'] : 0;
        $checked = $row !== null ? (int) $row['checked_at'] : 0;

        foreach ($fields as $key => $value) {
            if ($key === 'expires_at') {
                $expires = (int) $value;
            } elseif ($key === 'checked_at') {
                $checked = (int) $value;
            } elseif (\array_key_exists($key, self::DEFAULTS)) {
                if ($key === 'sessions') {
                    $value = array_values(array_unique((array) $value));
                } elseif ($key === 'events') {
                    $value = array_values((array) $value);
                }
                $data[$key] = $value;
            }
        }

        // Read back by the cron while a page may still be paid and nothing has finished the order.
        $open = empty($data['finished']) && ($data['session_id'] ?? '') !== '' ? 1 : 0;
        $json = (string) json_encode($data);

        if ($row === null) {
            $now = time();
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__orboni_j2commerce'))
                ->columns($db->quoteName(['order_id', 'data', 'open', 'expires_at', 'checked_at', 'created_at']))
                ->values(':order, :data, :open, :expires, :checked, :created')
                ->bind(':order', $orderId)
                ->bind(':data', $json)
                ->bind(':open', $open, ParameterType::INTEGER)
                ->bind(':expires', $expires, ParameterType::INTEGER)
                ->bind(':checked', $checked, ParameterType::INTEGER)
                ->bind(':created', $now, ParameterType::INTEGER);
        } else {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__orboni_j2commerce'))
                ->set($db->quoteName('data').' = :data')
                ->set($db->quoteName('open').' = :open')
                ->set($db->quoteName('expires_at').' = :expires')
                ->set($db->quoteName('checked_at').' = :checked')
                ->where($db->quoteName('order_id').' = :order')
                ->bind(':data', $json)
                ->bind(':open', $open, ParameterType::INTEGER)
                ->bind(':expires', $expires, ParameterType::INTEGER)
                ->bind(':checked', $checked, ParameterType::INTEGER)
                ->bind(':order', $orderId);
        }
        $db->setQuery($query)->execute();
    }

    /**
     * Orders whose payment page may still be paid, least recently read first:
     * a page closed less than two days ago, made in the last fourteen, not
     * read in the last 50 minutes. A new page counts as read 40 minutes
     * before it was made, so its first check comes ten minutes after.
     *
     * @return list<string>
     */
    public static function open(int $limit): array
    {
        $db = self::db();
        $since = time() - 14 * 86400;
        $closed = time() - 2 * 86400;
        $read = time() - Sync::RECHECK_SECONDS;
        $query = $db->getQuery(true)
            ->select($db->quoteName('order_id'))
            ->from($db->quoteName('#__orboni_j2commerce'))
            ->where($db->quoteName('open').' = 1')
            ->where($db->quoteName('created_at').' > :since')
            ->where($db->quoteName('expires_at').' > :closed')
            ->where($db->quoteName('checked_at').' < :read')
            ->order($db->quoteName('checked_at').' ASC')
            ->bind(':since', $since, ParameterType::INTEGER)
            ->bind(':closed', $closed, ParameterType::INTEGER)
            ->bind(':read', $read, ParameterType::INTEGER);

        return array_map('strval', (array) $db->setQuery($query, 0, $limit)->loadColumn());
    }

    /**
     * The order as J2Commerce keeps it now.
     *
     * @return array<string, mixed>|null
     */
    public static function order(string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }
        $db = self::db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(self::ORDER_FIELDS))
            ->from($db->quoteName('#__j2commerce_orders'))
            ->where($db->quoteName('order_id').' = :order')
            ->bind(':order', $orderId);
        $row = $db->setQuery($query)->loadAssoc();

        return \is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function row(string $orderId): ?array
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['data', 'expires_at', 'checked_at']))
            ->from($db->quoteName('#__orboni_j2commerce'))
            ->where($db->quoteName('order_id').' = :order')
            ->bind(':order', $orderId);
        $row = $db->setQuery($query)->loadAssoc();

        return \is_array($row) ? $row : null;
    }

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
