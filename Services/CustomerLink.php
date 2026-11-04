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
     * Otherwise the session's customer, if it has no email yet, gets the email and, for $name !== ''
     * (identities WordPress vouches for), the name. A customer that already
     * has an email is left alone and a new customer is created.
     */
    public static function resolve(Session $session, string $email, string $name = ''): Customer
    {
        $existing = Customer::getByEmail($email);

        if ($existing) {
            return $existing;
        }

        $current = VisitorCustomer::forSession($session);

        // Only a customer without any email is still an anonymous
        // placeholder (Ruling P9); anyone else is never modified.
        if (!$current->emails()->exists()) {
            // Returns false instead of throwing when the address is taken.
            if ($current->addEmail($email, true) !== false) {
                if ($name !== '') {
                    $current->first_name = $name;
                    $current->last_name = '';
                    $current->save();
                }

                return $current;
            }

            $taken = Customer::getByEmail($email);

            if ($taken) {
                return $taken;
            }
        }

        return Customer::create($email, $name !== '' ? ['first_name' => $name] : []);
    }

    /**
     * Makes $customer the customer of $conversation and of $session. This
     * conversation's customer threads written by the old placeholder move
     * with it; other browsers' threads stay as they are.
     *
     * $provided is the address the visitor gave (typed, account or billing
     * email). It becomes the conversation's customer_email when it belongs
     * to $customer, so replies go where the visitor chose; otherwise the
     * customer's main email does.
     */
    public static function attach(Conversation $conversation, Customer $customer, Session $session, string $provided): void
    {
        $oldId = (int) $session->customer_id;
        $email = $provided !== '' && $customer->emails()->where('email', $provided)->exists()
            ? $provided
            : $customer->getMainEmail();

        $before = $conversation->customer_email;

        if ((int) $conversation->customer_id !== (int) $customer->id) {
            $conversation->changeCustomer($email, $customer, null, $customer);
        } elseif ($email !== '' && $conversation->customer_email !== $email) {
            // The placeholder was upgraded in place (Ruling C8).
            $conversation->customer_email = $email;
            $conversation->save();
        }

        // The visitor who just gave an address is still on the page
        // (Ruling D3).
        if ($conversation->customer_email !== $before) {
            DeliveryClock::restart($conversation);
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
