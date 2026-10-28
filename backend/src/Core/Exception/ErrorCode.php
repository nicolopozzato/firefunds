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
    case AccountDisabled = 'account_disabled';
    case PasswordChangeRequired = 'password_change_required';
    case PasswordUnchanged = 'password_unchanged';

    // Users (admin)
    case LastAdministrator = 'last_administrator';

    // Something looked up by id that doesn't exist (or isn't the user's)
    case AccountNotFound = 'account_not_found';
    case CategoryNotFound = 'category_not_found';
    case TagNotFound = 'tag_not_found';
    case TransactionNotFound = 'transaction_not_found';
    case InstrumentNotFound = 'instrument_not_found';
    case InvestmentTransactionNotFound = 'investment_transaction_not_found';
    case RestoreUnavailable = 'restore_unavailable';
    case CurrencyNotFound = 'currency_not_found';

    // Accounts
    case AccountInUse = 'account_in_use';
    case TransferTargetInvalid = 'transfer_target_invalid';
    case AccountMoveTypeMismatch = 'account_move_type_mismatch';
    case AccountCurrencyLocked = 'account_currency_locked';
    case CurrencyNotAvailable = 'currency_not_available';
    case ReconcileAssetOnly = 'reconcile_asset_only';
    case ReconciliationDifference = 'reconciliation_difference';

    // Transactions
    case AccountWithoutCurrency = 'account_without_currency';
    case AmountRequiredOtherCurrency = 'amount_required_other_currency';
    case AccountPairNotAllowed = 'account_pair_not_allowed';
    case DestinationAmountRequired = 'destination_amount_required';

    // Recurring transactions
    case RecurringNotFound = 'recurring_not_found';
    case NothingToConfirm = 'nothing_to_confirm';
    case TransactionNotLinkable = 'transaction_not_linkable';

    // Notifications
    case NotificationNotFound = 'notification_not_found';
    case PushDeviceNotFound = 'push_device_not_found';
    case PushFailed = 'push_failed';

    // Investments
    case InvestmentAccountRequired = 'investment_account_required';
    case InstrumentInUse = 'instrument_in_use';
    case PriceNotFound = 'price_not_found';
    case PriceDateInFuture = 'price_date_in_future';
    case PriceWithoutProviderValue = 'price_without_provider_value';
    case PriceFromProvider = 'price_from_provider';

    // Backups (admin)
    case BackupNotFound = 'backup_not_found';
    case BackupFailed = 'backup_failed';
    case BackupInProgress = 'backup_in_progress';
    case BackupFromNewerVersion = 'backup_from_newer_version';
    case BackupUnreadable = 'backup_unreadable';

    // Exchange rates
    case RateCurrencyInvalid = 'rate_currency_invalid';
    case RateDateInFuture = 'rate_date_in_future';
    case RateNotFound = 'rate_not_found';

    // Scheduled tasks
    case TaskNotFound = 'task_not_found';
    case TaskAlreadyRunning = 'task_already_running';

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
            self::AccountDisabled => 'This user has been disabled.',
            self::PasswordChangeRequired => 'Choose a new password first.',
            self::PasswordUnchanged => 'The new password must be different from the current one.',
            self::LastAdministrator => 'There must be at least one active administrator.',
            self::AccountNotFound => 'Account not found.',
            self::CategoryNotFound => 'Category not found.',
            self::TagNotFound => 'Tag not found.',
            self::TransactionNotFound => 'Transaction not found.',
            self::InstrumentNotFound => 'Investment instrument not found.',
            self::InvestmentTransactionNotFound => 'Investment transaction not found.',
            self::RestoreUnavailable => 'This can no longer be restored.',
            self::CurrencyNotFound => 'Currency not found.',
            self::AccountInUse => 'This account has an opening balance, {transactions} transaction(s) or {investmentTransactions} investment transaction(s): move them to another account or delete them too.',
            self::TransferTargetInvalid => 'Transactions can only be moved to another account of the same type, kind and currency.',
            self::AccountMoveTypeMismatch => 'An account can only be moved next to another account of the same type.',
            self::AccountCurrencyLocked => 'The currency of an account with transactions cannot be changed.',
            self::CurrencyNotAvailable => 'Currency "{currency}" is not available.',
            self::ReconcileAssetOnly => 'Only an asset account can be checked against a bank balance.',
            self::ReconciliationDifference => 'The balance differs by {difference} from the one given: settle the difference first.',
            self::RecurringNotFound => 'Recurring transaction not found.',
            self::TransactionNotLinkable => 'This transaction is already part of a recurring transaction, or is not on its accounts.',
            self::NotificationNotFound => 'Notification not found.',
            self::PushDeviceNotFound => 'Device not found.',
            self::PushFailed => 'The push service did not accept the notification for this device.',
            self::NothingToConfirm => 'Nothing of this recurring transaction is waiting to be confirmed.',
            self::AccountWithoutCurrency => 'The asset account involved in this transaction has no currency set.',
            self::DestinationAmountRequired => 'A transfer from {source} to {destination} needs the amount that arrived.',
            self::AmountRequiredOtherCurrency => 'The instrument is quoted in {instrument} and the account is in {account}, with no rate for that day: enter the amount the broker charged.',
            self::AccountPairNotAllowed => 'A transaction from {source} to {destination} is not allowed.',
            self::InvestmentAccountRequired => 'Investment transactions require an investment asset account.',
            self::InstrumentInUse => 'This instrument has {transactions} investment transaction(s): confirm deleting them too.',
            self::PriceNotFound => 'No price recorded on that day.',
            self::PriceDateInFuture => 'A price can only be recorded for today or a day before.',
            self::PriceWithoutProviderValue => 'This price was entered by hand: there is no imported value to restore.',
            self::PriceFromProvider => 'An imported price cannot be removed: restore the imported value instead.',
            self::BackupNotFound => 'Backup not found.',
            self::BackupFailed => 'The backup or restore could not be completed: {reason}',
            self::BackupInProgress => 'Another backup or restore is running: try again in a moment.',
            self::BackupFromNewerVersion => 'This backup comes from FireFunds {version}, newer than the {current} that is running: update FireFunds first.',
            self::BackupUnreadable => 'This file is not a FireFunds backup, or it is damaged.',
            self::RateCurrencyInvalid => 'There is no rate for {currency} against your currency.',
            self::RateDateInFuture => 'A rate can only be entered for today or a day before.',
            self::RateNotFound => 'You have not entered a rate for that day.',
            self::TaskNotFound => 'There is no such scheduled task.',
            self::TaskAlreadyRunning => 'This task is running already: try again in a moment.',
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
