import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { cn, formatDateTime, formatMoney, moneyColorClass } from '@/lib/utils'
import { isIncomplete, newAdjustmentRow, type AdjustmentRow } from '@/lib/account-adjustments'

interface AccountAdjustmentsFieldProps {
  rows: AdjustmentRow[]
  onChange: (rows: AdjustmentRow[]) => void
  currencySymbol: string | null
  /** Tells the form whether a row is open, so it isn't saved with that row's changes left behind. */
  onEditingChange: (isEditing: boolean) => void
  /** The form tried to save while a row was open. */
  showOpenRowError: boolean
}

/** The row being edited: a copy, written back on "Done", dropped on "Cancel". */
interface Draft {
  row: AdjustmentRow
  isNew: boolean
  showErrors: boolean
}

/**
 * An asset account's other changes to its balance (AccountAdjustment):
 * money that is neither income nor an expense, e.g. cash already there or a
 * correction. Kept here only, never listed among the transactions. Part of
 * the form's balance section, under the opening balance.
 *
 * A compact list (what, when, how much); the fields open for one row at a
 * time, the one being added or edited.
 */
export default function AccountAdjustmentsField({
  rows,
  onChange,
  currencySymbol,
  onEditingChange,
  showOpenRowError,
}: AccountAdjustmentsFieldProps) {
  const { t } = useTranslation()
  const [draft, setDraft] = useState<Draft | null>(null)

  const open = (next: Draft | null) => {
    setDraft(next)
    onEditingChange(next !== null)
  }

  const commit = () => {
    if (!draft) return
    if (isIncomplete(draft.row)) {
      setDraft({ ...draft, showErrors: true })
      return
    }
    onChange(draft.isNew ? [...rows, draft.row] : rows.map((row) => (row.key === draft.row.key ? draft.row : row)))
    open(null)
  }

  const remove = (key: string) => {
    onChange(rows.filter((row) => row.key !== key))
    if (draft?.row.key === key) open(null)
  }

  const updateDraft = (changes: Partial<AdjustmentRow>) => {
    if (draft) setDraft({ ...draft, row: { ...draft.row, ...changes } })
  }

  const isMissing = !!draft && draft.showErrors && isIncomplete(draft.row)

  const editor = draft && (
    <div className="space-y-3 rounded-lg border bg-muted/40 p-3">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-[10rem_10rem_minmax(0,1fr)]">
        <div className="min-w-0 space-y-1.5">
          <Label htmlFor="adjustment-date">{t('accountsCommon.adjustmentDate')}</Label>
          <Input
            id="adjustment-date"
            type="date"
            value={draft.row.date}
            onChange={(e) => updateDraft({ date: e.target.value })}
          />
        </div>
        <div className="min-w-0 space-y-1.5">
          <Label htmlFor="adjustment-amount">{t('accountsCommon.adjustmentAmount')}</Label>
          <Input
            id="adjustment-amount"
            type="number"
            step="0.01"
            value={draft.row.amount}
            onChange={(e) => updateDraft({ amount: e.target.value })}
          />
        </div>
        <div className="col-span-2 min-w-0 space-y-1.5 sm:col-span-1">
          <Label htmlFor="adjustment-description">{t('accountsCommon.adjustmentDescription')}</Label>
          <Input
            id="adjustment-description"
            maxLength={255}
            value={draft.row.description}
            onChange={(e) => updateDraft({ description: e.target.value })}
          />
        </div>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className={cn('text-xs', isMissing ? 'text-destructive' : 'text-muted-foreground')}>
          {isMissing ? t('accountsCommon.adjustmentIncomplete') : t('accountsCommon.adjustmentAmountHint')}
        </p>
        <div className="flex gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={() => open(null)}>
            {t('common.cancel')}
          </Button>
          <Button type="button" size="sm" onClick={commit}>
            {t('accountsCommon.adjustmentDone')}
          </Button>
        </div>
      </div>
    </div>
  )

  return (
    <section className="space-y-3 border-t pt-6">
      <div className="space-y-1">
        <h3 className="text-sm font-semibold">
          {t('accountsCommon.sectionAdjustments')}
          <span className="ml-2 font-normal text-muted-foreground">{t('accountsCommon.optional')}</span>
        </h3>
        <p className="text-xs text-muted-foreground">{t('accountsCommon.adjustmentsHint')}</p>
      </div>

      {rows.length > 0 && (
        <ul className="divide-y rounded-lg border">
          {rows.map((row) =>
            draft && !draft.isNew && draft.row.key === row.key ? (
              <li key={row.key} className="p-2">
                {editor}
              </li>
            ) : (
              <li key={row.key} className="flex items-center gap-3 py-2 pl-3 pr-1">
                <button
                  type="button"
                  className="min-w-0 flex-1 text-left"
                  onClick={() => open({ row, isNew: false, showErrors: false })}
                >
                  <div className={cn('truncate text-sm', !row.description && 'text-muted-foreground')}>
                    {row.description || t('accountsCommon.adjustmentNoDescription')}
                  </div>
                  <div className="text-xs text-muted-foreground">{formatDateTime(row.date)}</div>
                </button>
                <span className={cn('shrink-0 text-sm font-medium tabular-nums', moneyColorClass(row.amount))}>
                  {formatMoney(row.amount, currencySymbol)}
                </span>
                <div className="flex shrink-0">
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8"
                    aria-label={t('accountsCommon.editAdjustment')}
                    title={t('accountsCommon.editAdjustment')}
                    onClick={() => open({ row, isNew: false, showErrors: false })}
                  >
                    <Pencil className="h-4 w-4" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8"
                    aria-label={t('accountsCommon.removeAdjustment')}
                    title={t('accountsCommon.removeAdjustment')}
                    onClick={() => remove(row.key)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </li>
            )
          )}
        </ul>
      )}

      {draft?.isNew && editor}

      {showOpenRowError && draft && <p className="text-xs text-destructive">{t('accountsCommon.adjustmentOpen')}</p>}

      {!draft && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="w-fit"
          onClick={() => open({ row: newAdjustmentRow(), isNew: true, showErrors: false })}
        >
          <Plus className="mr-2 h-4 w-4" />
          {t('accountsCommon.addAdjustment')}
        </Button>
      )}
    </section>
  )
}
