import { ArrowDownCircle, ArrowDownToLine, ArrowUpCircle, ArrowUpToLine, Repeat } from 'lucide-react'
import { isDeposit, isWithdrawal } from '@/lib/transaction-sides'
import type { Transaction } from '@/types/transaction'

/** The icon in front of a phone list row, by kind of transaction. */
export default function TransactionKindIcon({ transaction }: { transaction: Transaction }) {
  if (transaction.kind === 'investmentBuy') return <ArrowDownToLine className="h-4 w-4 text-negative" />
  if (transaction.kind === 'investmentSell') return <ArrowUpToLine className="h-4 w-4 text-positive" />
  if (isDeposit(transaction)) return <ArrowDownCircle className="h-4 w-4 text-positive" />
  if (isWithdrawal(transaction)) return <ArrowUpCircle className="h-4 w-4 text-negative" />
  return <Repeat className="h-4 w-4 text-info" />
}
