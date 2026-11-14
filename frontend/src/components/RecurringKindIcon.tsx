import { ArrowDownCircle, ArrowUpCircle, Repeat, TrendingUp } from 'lucide-react'
import type { RecurringKind } from '@/types/recurring'

/** A recurring transaction's kind, as the transaction lists show it. */
export default function RecurringKindIcon({ kind }: { kind: RecurringKind }) {
  if (kind === 'deposit') return <ArrowDownCircle className="h-4 w-4 text-positive" />
  if (kind === 'withdrawal') return <ArrowUpCircle className="h-4 w-4 text-negative" />
  if (kind === 'purchase') return <TrendingUp className="h-4 w-4 text-invest" />
  return <Repeat className="h-4 w-4 text-info" />
}
