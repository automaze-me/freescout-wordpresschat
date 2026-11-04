<?php

namespace Modules\WordPressChat\Services;

use App\Customer;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\WordPressChat;

class VisitorCustomer
{
    /**
     * The FreeScout customer behind a visitor session. An anonymous visitor
     * gets an email-less customer on their first message, identified in the
     * Website chat channel by the session's public id. A session whose
     * customer has since been deleted gets a fresh one.
     */
    public static function forSession(Session $session): Customer
    {
        if ($session->customer_id) {
            $customer = Customer::find($session->customer_id);

            if ($customer) {
                return $customer;
            }
        }

        $customer = Customer::createWithoutEmail(['first_name' => __('Website visitor')]);
        $customer->addChannel(WordPressChat::CHANNEL, $session->public_id);

        $session->customer_id = $customer->id;
        $session->save();

        return $customer;
    }
}
