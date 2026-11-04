<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use App\Customer;
use App\Thread;
use Modules\WordPressChat\Entities\Session;

/**
 * Links a visitor session to a FreeScout customer (spec §6.1). An existing
 * customer is never modified; only the session's own placeholder may be
 * upgraded (Ruling P9). Runs inside the caller's transaction: no try/catch
 * around SQL here (a failed statement aborts a PostgreSQL transaction).
 */
class CustomerLink
{
    /**
     * The customer for $email. An existing one is returned untouched.
     * Otherwise the session's placeholder gets the email and, for $name !== ''
     * (identities WordPress vouches for), the name.
     */
    public static function resolve(Session $session, string $email, string $name = ''): Customer
    {
        $existing = Customer::getByEmail($email);

        if ($existing) {
            return $existing;
        }

        $placeholder = VisitorCustomer::forSession($session);

        // Returns false instead of throwing when the address is taken.
        if ($placeholder->addEmail($email, true) === false) {
            $taken = Customer::getByEmail($email);

            if ($taken) {
                return $taken;
            }
        }

        if ($name !== '') {
            $placeholder->first_name = $name;
            $placeholder->last_name = '';
            $placeholder->save();
        }

        return $placeholder;
    }

    /**
     * Makes $customer the customer of $conversation and of $session. This
     * conversation's customer threads written by the old placeholder move
     * with it; other browsers' threads stay as they are.
     */
    public static function attach(Conversation $conversation, Customer $customer, Session $session): void
    {
        $oldId = (int) $session->customer_id;
        $email = $customer->getMainEmail();

        if ((int) $conversation->customer_id !== (int) $customer->id) {
            $conversation->changeCustomer($email, $customer, null, $customer);
        } elseif ($email !== '' && $conversation->customer_email !== $email) {
            // The placeholder was upgraded in place (Ruling C8).
            $conversation->customer_email = $email;
            $conversation->save();
        }

        if ($oldId > 0 && $oldId !== (int) $customer->id) {
            Thread::where('conversation_id', $conversation->id)
                ->where('type', Thread::TYPE_CUSTOMER)
                ->where('created_by_customer_id', $oldId)
                ->update(['customer_id' => $customer->id, 'created_by_customer_id' => $customer->id]);
        }

        if ((int) $session->customer_id !== (int) $customer->id) {
            $session->customer_id = $customer->id;
            $session->save();
        }
    }
}
