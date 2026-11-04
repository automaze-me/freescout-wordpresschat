<?php

namespace Modules\WordPressChat;

/**
 * Identity levels of a browser on a conversation (spec §6.1). "Lowest"
 * means the smallest number (Ruling P1): an order-only browser ranks below
 * an email-typed one, so a conversation mixing both keeps the warning box.
 */
class Level
{
    const ANONYMOUS = 1;
    const ORDER     = 2;
    const EMAIL     = 3;
    const ACCOUNT   = 4;
    const VERIFIED  = 5;

    /** The name the public API uses. An unknown level reads as the lowest. */
    public static function name(int $level): string
    {
        switch ($level) {
            case self::ORDER:
                return 'order';
            case self::EMAIL:
                return 'email';
            case self::ACCOUNT:
                return 'account';
            case self::VERIFIED:
                return 'verified';
            default:
                return 'anonymous';
        }
    }

    /** The badge text agents see. */
    public static function label(int $level): string
    {
        switch ($level) {
            case self::ORDER:
                return __('Order number only');
            case self::EMAIL:
                return __('Email not verified');
            case self::ACCOUNT:
                return __('Logged-in account');
            case self::VERIFIED:
                return __('Email verified');
            default:
                return __('Anonymous');
        }
    }
}
