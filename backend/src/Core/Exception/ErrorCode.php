<?php

namespace App\Core\Exception;

/**
 * Every error the user can get from the API, sent as `code` (with `params`)
 * by ExceptionListener. The frontend shows it in the user's language from
 * `errors.api.<code>` in frontend/src/i18n/locales/{it,en}.json: a new case
 * needs both translations. message() is the English text for logs and API
 * clients, never shown in the app.
 */
enum ErrorCode: string
{
    // Auth
    case RegistrationDisabled = 'registration_disabled';
    case EmailAlreadyRegistered = 'email_already_registered';
    case CurrentPasswordIncorrect = 'current_password_incorrect';
    case TooManyLoginAttempts = 'too_many_login_attempts';
    case UserNotFound = 'user_not_found';

    // Something looked up by id that doesn't exist (or isn't the user's)
    case AccountNotFound = 'account_not_found';
    case CategoryNotFound = 'category_not_found';
    case TagNotFound = 'tag_not_found';
    case TransactionNotFound = 'transaction_not_found';
    case InstrumentNotFound = 'instrument_not_found';
    case InvestmentTransactionNotFound = 'investment_transaction_not_found';
    case CurrencyNotFound = 'currency_not_found';

    // Accounts
    case AccountInUse = 'account_in_use';
    case TransferTargetInvalid = 'transfer_target_invalid';
    case AccountMoveTypeMismatch = 'account_move_type_mismatch';
    case AccountCurrencyLocked = 'account_currency_locked';
    case CurrencyNotAvailable = 'currency_not_available';

    // Transactions
    case AccountWithoutCurrency = 'account_without_currency';
    case AccountPairNotAllowed = 'account_pair_not_allowed';

    // Investments
    case InvestmentAccountRequired = 'investment_account_required';
    case AmountFarFromQuantityTimesPrice = 'amount_far_from_quantity_times_price';
    case InstrumentInUse = 'instrument_in_use';
    case PriceNotFound = 'price_not_found';
    case PriceDateInFuture = 'price_date_in_future';
    case PriceWithoutProviderValue = 'price_without_provider_value';
    case PriceFromProvider = 'price_from_provider';

    // Currencies (admin)
    case CurrencyCodeTaken = 'currency_code_taken';
    case CurrencyInUse = 'currency_in_use';

    // Backups (admin)
    case BackupNotFound = 'backup_not_found';
    case BackupFailed = 'backup_failed';
    case BackupInProgress = 'backup_in_progress';
    case BackupFromNewerVersion = 'backup_from_newer_version';
    case BackupUnreadable = 'backup_unreadable';

    // Periods
    case InvalidDateRange = 'invalid_date_range';
    case DateRangeInverted = 'date_range_inverted';
    case DateRangeTooLong = 'date_range_too_long';

    /**
     * @param array<string, string|int> $params filled into the {name} placeholders
     */
    public function message(array $params = []): string
    {
        $template = match ($this) {
            self::RegistrationDisabled => 'Registration is currently disabled.',
            self::EmailAlreadyRegistered => 'Email already registered.',
            self::CurrentPasswordIncorrect => 'Current password is incorrect.',
            self::TooManyLoginAttempts => 'Too many failed login attempts: try again in {minutes} minute(s).',
            self::UserNotFound => 'User not found.',
            self::AccountNotFound => 'Account not found.',
            self::CategoryNotFound => 'Category not found.',
            self::TagNotFound => 'Tag not found.',
            self::TransactionNotFound => 'Transaction not found.',
            self::InstrumentNotFound => 'Investment instrument not found.',
            self::InvestmentTransactionNotFound => 'Investment transaction not found.',
            self::CurrencyNotFound => 'Currency not found.',
            self::AccountInUse => 'This account has an opening balance, {transactions} transaction(s) or {investmentTransactions} investment transaction(s): move them to another account or delete them too.',
            self::TransferTargetInvalid => 'Transactions can only be moved to another account of the same type, kind and currency.',
            self::AccountMoveTypeMismatch => 'An account can only be moved next to another account of the same type.',
            self::AccountCurrencyLocked => 'The currency of an account with transactions cannot be changed.',
            self::CurrencyNotAvailable => 'Currency "{currency}" is not available.',
            self::AccountWithoutCurrency => 'The asset account involved in this transaction has no currency set.',
            self::AccountPairNotAllowed => 'A transaction from {source} to {destination} is not allowed.',
            self::InvestmentAccountRequired => 'Investment transactions require an investment asset account.',
            self::AmountFarFromQuantityTimesPrice => 'The amount {amount} is too far from quantity × unit price ({expected}): check the figures.',
            self::InstrumentInUse => 'This instrument has {transactions} investment transaction(s): confirm deleting them too.',
            self::PriceNotFound => 'No price recorded on that day.',
            self::PriceDateInFuture => 'A price can only be recorded for today or a day before.',
            self::PriceWithoutProviderValue => 'This price was entered by hand: there is no imported value to restore.',
            self::PriceFromProvider => 'An imported price cannot be removed: restore the imported value instead.',
            self::CurrencyCodeTaken => 'A currency with this code already exists.',
            self::CurrencyInUse => 'This currency is in use by one or more accounts and cannot be deleted.',
            self::BackupNotFound => 'Backup not found.',
            self::BackupFailed => 'The backup or restore could not be completed: {reason}',
            self::BackupInProgress => 'Another backup or restore is running: try again in a moment.',
            self::BackupFromNewerVersion => 'This backup comes from FireFunds {version}, newer than the {current} that is running: update FireFunds first.',
            self::BackupUnreadable => 'This file is not a FireFunds backup, or it is damaged.',
            self::InvalidDateRange => 'Invalid date range.',
            self::DateRangeInverted => '"from" must be on or before "to".',
            self::DateRangeTooLong => 'A date range can span at most {years} years.',
        };

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{'.$name.'}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
