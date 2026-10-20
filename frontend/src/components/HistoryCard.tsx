import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown, History } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { cn, formatDateTime, formatTime24h } from '@/lib/utils'
import type { AuditLogChange, AuditLogEntry } from '@/types/audit-log'

/** A value in a history line, as before → after. */
export function HistoryValue({ children }: { children: string }) {
  return <code className="rounded bg-muted px-1 py-0.5 text-xs">{children}</code>
}

interface HistoryCardProps {
  entries: AuditLogEntry[] | undefined
  isLoading: boolean
  /** "Transazione creata", "Conto creato"… */
  createdLabel: string
  fieldLabel: (field: string) => string
  /** A value as shown, or null to show it as "empty" (isEmptyValue() in lib/audit-log tells the usual empty ones). */
  formatValue: (field: string, value: unknown) => string | null
  /** A line of its own for some changes (e.g. an account's adjustments); null for the usual "field: before → after". */
  renderChange?: (change: AuditLogChange) => ReactNode | null
  /** Phones: no frame and no side padding, as a section of a form. */
  flush?: boolean
}

/**
 * The history of a record (transaction, trade, account), the same everywhere:
 * closed until opened, with how many entries; each entry its time and what
 * the save changed, newest first. A long one scrolls inside the card on
 * larger screens.
 */
export default function HistoryCard({
  entries,
  isLoading,
  createdLabel,
  fieldLabel,
  formatValue,
  renderChange,
  flush = false,
}: HistoryCardProps) {
  const { t } = useTranslation()

  const value = (field: string, raw: unknown) => formatValue(field, raw) ?? t('history.emptyValue')

  return (
    <Card className={cn(flush && 'max-md:border-0 max-md:bg-transparent max-md:shadow-none')}>
      <details className="group">
        <summary
          className={cn(
            'flex cursor-pointer list-none items-center gap-2 p-6 text-sm font-medium text-muted-foreground max-md:p-4 [&::-webkit-details-marker]:hidden',
            flush && 'max-md:px-0'
          )}
        >
          <History className="h-4 w-4" />
          {t('history.title')}
          {entries && entries.length > 0 && <span className="tabular-nums">({entries.length})</span>}
          <ChevronDown className="ml-auto h-4 w-4 transition-transform group-open:rotate-180" />
        </summary>
        <CardContent className={cn('max-h-96 overflow-y-auto max-md:max-h-none max-md:px-4 max-md:pb-4', flush && 'max-md:px-0')}>
          {isLoading ? (
            <Skeleton className="h-5 w-1/2" />
          ) : entries && entries.length > 0 ? (
            <ul className="divide-y divide-border">
              {entries.map((entry) => (
                <li key={entry.id} className="space-y-1.5 py-3 first:pt-0 last:pb-0">
                  <div className="text-xs text-muted-foreground">
                    {formatDateTime(entry.createdAt)} · {formatTime24h(entry.createdAt)}
                  </div>
                  {entry.action === 'created' ? (
                    <div className="text-sm">{createdLabel}</div>
                  ) : (
                    <ul className="space-y-1">
                      {(entry.changes ?? []).map((change, index) => (
                        <li key={`${entry.id}-${index}`} className="text-sm">
                          {renderChange?.(change) ?? (
                            <>
                              {fieldLabel(change.field)}: <HistoryValue>{value(change.field, change.oldValue)}</HistoryValue>
                              {' → '}
                              <HistoryValue>{value(change.field, change.newValue)}</HistoryValue>
                            </>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground">{t('history.empty')}</p>
          )}
        </CardContent>
      </details>
    </Card>
  )
}
