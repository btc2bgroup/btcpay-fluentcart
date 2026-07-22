<?php
/**
 * Minimal FluentCart class stubs mirroring the interfaces the plugin
 * actually touches, so the plugin classes load and run under PHPUnit.
 */

namespace FluentCart\App\Helpers {

    class Helper
    {
        public static function encryptKey($value)
        {
            return 'enc:' . base64_encode((string)$value);
        }

        public static function decryptKey($value)
        {
            if (is_string($value) && strpos($value, 'enc:') === 0) {
                return base64_decode(substr($value, 4));
            }
            return $value;
        }
    }

    class Status
    {
        const TRANSACTION_SUCCEEDED = 'succeeded';
        const TRANSACTION_FAILED = 'failed';
        const TRANSACTION_REFUNDED = 'refunded';
    }

    class StatusHelper
    {
        public static $synced = [];

        private $order;

        public function __construct($order)
        {
            $this->order = $order;
        }

        public function syncOrderStatuses($transaction)
        {
            self::$synced[] = ['order' => $this->order, 'transaction' => $transaction];
            return true;
        }
    }
}

namespace FluentCart\Framework\Support {

    class Arr
    {
        public static function get($array, $key, $default = null)
        {
            if (!is_array($array)) {
                return $default;
            }

            if (array_key_exists($key, $array)) {
                return $array[$key];
            }

            $segments = explode('.', (string)$key);
            $current = $array;

            foreach ($segments as $segment) {
                if (is_array($current) && array_key_exists($segment, $current)) {
                    $current = $current[$segment];
                } else {
                    return $default;
                }
            }

            return $current;
        }
    }
}

namespace FluentCart\Api {

    class StoreSettings
    {
        /** Injected per test; defaults to a live store. */
        public static $values = ['order_mode' => 'live'];

        public function get($key = '', $default = null)
        {
            if (!$key) {
                return static::$values;
            }
            return static::$values[$key] ?? $default;
        }
    }
}

namespace FluentCart\App\Modules\PaymentMethods\Core {

    abstract class BaseGatewaySettings
    {
        /** Injected per test via BTCPayTests\TestCase::setGatewaySettings() */
        public static $testSettings = [];

        public function __construct()
        {
        }

        public function getCachedSettings()
        {
            return static::$testSettings;
        }

        abstract public function getMode();
    }

    abstract class AbstractPaymentGateway
    {
        public $settings;

        public function __construct($settings = null, $subscriptionHandler = null)
        {
            $this->settings = $settings;
        }

        abstract public function meta(): array;

        public function getMeta($key)
        {
            $meta = $this->meta();
            return $meta[$key] ?? null;
        }

        public function getSuccessUrl($transaction)
        {
            return 'https://shop.example.com/?fct_redirect=yes&trx_hash=' . $transaction->uuid;
        }

        public function getCancelUrl()
        {
            return 'https://shop.example.com/checkout?status=cancelled';
        }

        public function getSettings(...$args)
        {
            return $this->settings;
        }
    }
}

namespace FluentCart\App\Services\Payments {

    #[\AllowDynamicProperties]
    class PaymentInstance
    {
        public $order;
        public $transaction;
        public $subscription = null;

        public function __construct($order = null, $transaction = null)
        {
            $this->order = $order;
            $this->transaction = $transaction;
        }
    }
}

namespace FluentCart\App\Models {

    class ModelQuery
    {
        private $records;
        private $wheres = [];

        public function __construct(array $records)
        {
            $this->records = $records;
        }

        public function where($column, $value)
        {
            $this->wheres[$column] = $value;
            return $this;
        }

        public function first()
        {
            foreach ($this->records as $record) {
                $matches = true;
                foreach ($this->wheres as $column => $value) {
                    if (($record->$column ?? null) !== $value) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    return $record;
                }
            }
            return null;
        }
    }

    #[\AllowDynamicProperties]
    class Order
    {
        public static $records = [];

        public $id;
        public $uuid;
        public $customer;
        public $type = 'single';

        public static function query()
        {
            return new ModelQuery(static::$records);
        }
    }

    #[\AllowDynamicProperties]
    class OrderTransaction
    {
        public static $records = [];

        public $uuid;
        public $order_id;
        public $status = 'pending';
        public $payment_method = 'btcpay';
        public $payment_method_type;
        public $vendor_charge_id;
        public $currency;
        public $total;
        public $meta = [];

        public $saveCount = 0;

        public static function query()
        {
            return new ModelQuery(static::$records);
        }

        public function fill($data)
        {
            foreach ($data as $key => $value) {
                $this->$key = $value;
            }
            return $this;
        }

        public function save()
        {
            $this->saveCount++;
            return true;
        }
    }
}
