import type { Transaction } from '@/types/transaction'

/** Money leaving the tracked accounts: to an expense account, or to "external" (null destination). */
export function isWithdrawal(transaction: Transaction): boolean {
  return transaction.destinationAccount === null || transaction.destinationAccount.type === 'expense'
}

/** Money entering the tracked accounts: from a revenue account, or from "external" (null source). */
export function isDeposit(transaction: Transaction): boolean {
  return transaction.sourceAccount === null || transaction.sourceAccount.type === 'revenue'
}

/** Money moving between two tracked accounts (investment trades are not transfers). */
export function isTransfer(transaction: Transaction): boolean {
  return transaction.kind === 'money' && !isWithdrawal(transaction) && !isDeposit(transaction)
}
