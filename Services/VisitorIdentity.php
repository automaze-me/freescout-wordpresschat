<?php

namespace Modules\WordPressChat\Services;

use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Exceptions\AlreadyIdentifiedException;
use Modules\WordPressChat\Level;

/**
 * The identity flows. Each runs in one transaction whose first statement
 * locks the session row (Ruling C10), and never catches a failed SQL
 * statement inside it.
 */
class VisitorIdentity
{
    /**
     * The visitor typed an email (already sanitised by the caller). Only a
     * browser still at ANONYMOUS on its latest conversation may do this.
     *
     * @throws AlreadyIdentifiedException
     */
    public static function typedEmail(Session $session, string $email): void
    {
        DB::transaction(function () use ($session, $email) {
            $locked = Session::where('id', $session->id)->lockForUpdate()->first();

            $conversation = Visibility::latestConversation($locked);
            $grants = $conversation ? Visibility::grants($locked) : [];

            if (!$conversation
                || !isset($grants[$conversation->id])
                || $grants[$conversation->id]['level'] !== Level::ANONYMOUS
            ) {
                throw new AlreadyIdentifiedException();
            }

            $customer = CustomerLink::resolve($locked, $email);
            CustomerLink::attach($conversation, $customer, $locked);

            $locked->identity = Session::IDENTITY_EMAIL;
            $locked->save();

            IdentityLineItem::create($conversation, __('Visitor entered email :email', ['email' => $email]));
        });
    }
}
