import { isDeposit, isTransfer, isWithdrawal } from '@/lib/transaction-sides'
import type { Transaction } from '@/types/transaction'

/** A row of the unified lists is a money transaction or a trade (BUY / SELL). */
export function isInvestmentRow(transaction: Transaction): boolean {
  return transaction.kind === 'investmentBuy' || transaction.kind === 'investmentSell'
}

export function detailPath(transaction: Transaction): string {
  return isInvestmentRow(transaction) ? `/transactions/investments/${transaction.id}` : `/transactions/${transaction.id}`
}

export function editPath(transaction: Transaction): string {
  return isInvestmentRow(transaction)
    ? `/transactions/investments/${transaction.id}/edit`
    : `/transactions/${transaction.id}/edit`
}

export function clonePath(transaction: Transaction): string {
  return isInvestmentRow(transaction)
    ? `/transactions/investments/new?cloneFrom=${transaction.id}`
    : `/transactions/new?cloneFrom=${transaction.id}`
}

/**
 * The amount as a list shows it: money leaving is negative (withdrawals and
 * BUYs), money coming in positive (deposits and SELLs). A transfer has no
 * direction of its own: with `accountId` (the list of one account) it is
 * negative on its source and positive on its destination, in a list across
 * accounts it stays positive.
 */
export function signedAmount(transaction: Transaction, accountId?: string): number {
  if (isWithdrawal(transaction) || transaction.kind === 'investmentBuy') return -Number(transaction.amount)
  if (accountId !== undefined && isTransfer(transaction) && transaction.sourceAccount?.id === accountId) return -Number(transaction.amount)
  return Number(arrivedAmount(transaction, accountId))
}

/** A transfer between currencies credits its destination with another amount than the one that left the source. */
function arrivedAmount(transaction: Transaction, accountId?: string): string {
  const credited = accountId !== undefined && isTransfer(transaction) && transaction.destinationAccount?.id === accountId
  return credited && transaction.destinationAmount ? transaction.destinationAmount : transaction.amount
}

/** The currency the amount in the list of this account is in: the destination's own for a transfer that crossed currencies. */
export function amountCurrency(transaction: Transaction, accountId?: string): string {
  const credited = accountId !== undefined && isTransfer(transaction) && transaction.destinationAccount?.id === accountId
  return credited && transaction.destinationAmount && transaction.destinationCurrency ? transaction.destinationCurrency : transaction.currency
}

/** Negative (money out) and positive (money in) as everywhere; a transfer in the transfers' own colour. */
export function amountColorClass(transaction: Transaction): string {
  if (isWithdrawal(transaction) || transaction.kind === 'investmentBuy') return 'text-negative'
  if (isDeposit(transaction) || transaction.kind === 'investmentSell') return 'text-positive'
  if (isTransfer(transaction)) return 'text-info'
  return ''
}
