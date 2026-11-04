<?php

namespace Modules\WordPressChat\Services;

use App\Customer;
use App\CustomerChannel;
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

        // A channel row can outlive its customer (raw deletes). Core's
        // addChannel() swallows the duplicate-key error, but on PostgreSQL
        // that failed INSERT aborts the surrounding transaction. So clear
        // the way first: adopt a live customer that still owns the row
        // (the channel id is this session's random public id, so it can
        // only be ours, e.g. after a merge), or drop a dangling row.
        $stale = CustomerChannel::where('channel', WordPressChat::CHANNEL)
            ->where('channel_id', $session->public_id)
            ->first();

        if ($stale) {
            $owner = Customer::find($stale->customer_id);

            if ($owner) {
                $session->customer_id = $owner->id;
                $session->save();

                return $owner;
            }

            CustomerChannel::where('channel', WordPressChat::CHANNEL)
                ->where('channel_id', $session->public_id)
                ->delete();
        }

        $customer = Customer::createWithoutEmail(['first_name' => __('Website visitor')]);
        $customer->addChannel(WordPressChat::CHANNEL, $session->public_id);

        $session->customer_id = $customer->id;
        $session->save();

        return $customer;
    }
}
