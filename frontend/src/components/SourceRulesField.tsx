import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { GripVertical, Plus, Trash2 } from 'lucide-react'
import { DndContext, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core'
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { restrictToVerticalAxis } from '@dnd-kit/modifiers'
import { CSS } from '@dnd-kit/utilities'
import Field from '@/components/Field'
import NotificationReadingBadge from '@/components/NotificationReadingBadge'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import SegmentedControl from '@/components/ui/segmented-control'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useIsMobile } from '@/hooks/use-media-query'
import { useDebouncedValue } from '@/hooks/use-search'
import { ruleRows, sourcesApi, type RuleKind, type RuleRow, type RuleStat, type SourceReading, type SourceRule } from '@/lib/sources-api'
import { cn, formatShortDate, formatTime24h } from '@/lib/utils'

const KIND_BADGE: Record<RuleKind, 'destructive' | 'success' | 'secondary'> = { out: 'destructive', in: 'success', ignore: 'secondary' }

interface SourceRulesFieldProps {
  sourceId: string
  rules: RuleRow[]
  onChange: (rules: RuleRow[]) => void
  /** What each rule reads, in the rules' order; undefined while the rules just changed. */
  stats: RuleStat[] | undefined
}

/**
 * A source's rules, tried in order from the top: a compact list (kind,
 * template, what it has read so far) to drag into order; a rule opens in a
 * dialog (a panel from the bottom on phones) to write it.
 */
export default function SourceRulesField({ sourceId, rules, onChange, stats }: SourceRulesFieldProps) {
  const { t } = useTranslation()
  const [editing, setEditing] = useState<number | 'new' | null>(null)
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

  const handleDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over || active.id === over.id) return
    const from = rules.findIndex((rule) => rule.key === active.id)
    const to = rules.findIndex((rule) => rule.key === over.id)
    onChange(arrayMove(rules, from, to))
  }

  const save = (rule: SourceRule) => {
    onChange(editing === 'new' ? [...rules, ruleRows([rule])[0]] : rules.map((row, i) => (i === editing ? { ...row, ...rule } : row)))
    setEditing(null)
  }

  const remove = (index: number) => {
    onChange(rules.filter((_, i) => i !== index))
    setEditing(null)
  }

  return (
    <div className="space-y-3">
      {rules.length > 0 && (
        <DndContext sensors={sensors} collisionDetection={closestCenter} modifiers={[restrictToVerticalAxis]} onDragEnd={handleDragEnd}>
          <SortableContext items={rules.map((rule) => rule.key)} strategy={verticalListSortingStrategy}>
            <ul className="divide-y rounded-lg border">
              {rules.map((rule, index) => (
                <SortableRule key={rule.key} rule={rule} index={index} stat={stats?.[index]} onOpen={() => setEditing(index)} onRemove={() => remove(index)} />
              ))}
            </ul>
          </SortableContext>
        </DndContext>
      )}
      <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => setEditing('new')}>
        <Plus className="mr-2 h-4 w-4" />
        {t('sources.addRule')}
      </Button>

      <RuleDialog
        sourceId={sourceId}
        rules={rules}
        editing={editing}
        onSave={save}
        onRemove={typeof editing === 'number' ? () => remove(editing) : undefined}
        onClose={() => setEditing(null)}
      />
    </div>
  )
}

/** A rule's row: dragged by its handle (keyboard too: focus it, Space, arrows, Space), opened by a tap. */
function SortableRule({ rule, index, stat, onOpen, onRemove }: { rule: RuleRow; index: number; stat: RuleStat | undefined; onOpen: () => void; onRemove: () => void }) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: rule.key })

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Translate.toString(transform), transition }}
      className={cn('flex items-center gap-1 py-1.5 pl-1 pr-1', isDragging && 'relative z-10 rounded-lg bg-background shadow-lg')}
    >
      <button
        ref={setActivatorNodeRef}
        type="button"
        {...attributes}
        {...listeners}
        aria-label={t('sources.dragToReorder')}
        title={t('sources.dragToReorder')}
        className="flex h-10 w-8 shrink-0 cursor-grab touch-none items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing"
      >
        <GripVertical className="h-4 w-4" />
      </button>
      <button type="button" onClick={onOpen} className="min-w-0 flex-1 rounded-md px-1 py-1 text-left hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
        <span className="flex min-w-0 items-center gap-2">
          <span className="w-4 shrink-0 text-xs tabular-nums text-muted-foreground">{index + 1}</span>
          <Badge variant={KIND_BADGE[rule.kind]} className="shrink-0 font-normal">
            {t(`sources.kind_${rule.kind}`)}
          </Badge>
          <span className="min-w-0 truncate font-mono text-xs">{rule.pattern}</span>
        </span>
        <span className={cn('mt-1 block pl-6 text-xs', stat?.matches === 0 ? 'text-warning' : 'text-muted-foreground')}>
          {stat === undefined ? ' ' : <RuleStatText stat={stat} />}
        </span>
      </button>
      <Button type="button" variant="ghost" size="icon" className="h-10 w-10 shrink-0" aria-label={t('sources.removeRule')} title={t('sources.removeRule')} onClick={onRemove}>
        <Trash2 className="h-4 w-4" />
      </Button>
    </li>
  )
}

function RuleStatText({ stat }: { stat: RuleStat }) {
  const { t } = useTranslation()
  if (stat.matches === 0 || stat.lastMatch === null) return <>{t('sources.ruleNoMatches')}</>

  return <>{t('sources.ruleMatches', { count: stat.matches, date: formatShortDate(stat.lastMatch.slice(0, 10)) })}</>
}

interface RuleDialogProps {
  sourceId: string
  rules: RuleRow[]
  /** The rule open (its index), a new one, or none. */
  editing: number | 'new' | null
  onSave: (rule: SourceRule) => void
  onRemove?: () => void
  onClose: () => void
}

function RuleDialog({ editing, onClose, ...props }: RuleDialogProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const isOpen = editing !== null
  const title = t(editing === 'new' ? 'sources.addRule' : 'sources.editRule')
  // A fresh form for every rule opened.
  const body = isOpen && <RuleForm key={String(editing)} editing={editing} onClose={onClose} {...props} />

  if (isMobile) {
    return (
      <Drawer open={isOpen} onOpenChange={(open) => !open && onClose()}>
        <DrawerContent>
          <DrawerHeader>
            <DrawerTitle>{title}</DrawerTitle>
            <DrawerDescription className="sr-only">{t('sources.patternHint')}</DrawerDescription>
          </DrawerHeader>
          <div className="overflow-y-auto px-4 pb-[calc(env(safe-area-inset-bottom)+1rem)]">{body}</div>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="sr-only">{t('sources.patternHint')}</DialogDescription>
        </DialogHeader>
        {body}
      </DialogContent>
    </Dialog>
  )
}

const AMOUNT_TOKEN = /\{(importo|amount)\}/i

/**
 * The rule being written, tried as it is typed on every notification of the
 * source: how many it reads, and the latest few.
 */
function RuleForm({ sourceId, rules, editing, onSave, onRemove, onClose }: RuleDialogProps & { editing: number | 'new' }) {
  const { t } = useTranslation()
  const current = editing === 'new' ? null : rules[editing]
  const [kind, setKind] = useState<RuleKind>(current?.kind ?? 'out')
  const [pattern, setPattern] = useState(current?.pattern ?? '')
  const [showErrors, setShowErrors] = useState(false)
  const [reading, setReading] = useState<SourceReading | null>(null)
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const index = editing === 'new' ? rules.length : editing

  const error =
    pattern.trim() === '' ? t('sources.patternRequired') : kind !== 'ignore' && !AMOUNT_TOKEN.test(pattern) ? t('sources.patternNeedsAmount', { token: t('sources.tokenAmount') }) : null

  // Every rule as it would be with this one in its place.
  const typed = useDebouncedValue(JSON.stringify({ kind, pattern }), 300)
  useEffect(() => {
    const rule = JSON.parse(typed) as SourceRule
    if (rule.pattern.trim() === '') {
      setReading(null)
      return
    }
    const candidate: SourceRule[] = rules.map(({ kind, pattern }) => ({ kind, pattern }))
    candidate[index] = rule
    let active = true
    sourcesApi.preview(sourceId, candidate).then((result) => active && setReading(result), () => {})
    return () => {
      active = false
    }
  }, [typed, rules, index, sourceId])

  const insert = (token: string) => {
    const field = textareaRef.current
    const start = field?.selectionStart ?? pattern.length
    const end = field?.selectionEnd ?? pattern.length
    setPattern(pattern.slice(0, start) + token + pattern.slice(end))
    requestAnimationFrame(() => {
      field?.focus()
      field?.setSelectionRange(start + token.length, start + token.length)
    })
  }

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (error) {
      setShowErrors(true)
      return
    }
    onSave({ kind, pattern: pattern.trim() })
  }

  const tokens = kind === 'ignore' ? ['{*}'] : [t('sources.tokenAmount'), t('sources.tokenMerchant'), '{*}']
  const stat = reading?.ruleStats[index]
  const examples = (reading?.notifications ?? []).filter((notification) => notification.reading?.rule === index).slice(0, 3)

  return (
    <form onSubmit={submit} className="space-y-4">
      <Field label={t('sources.kind')}>
        <SegmentedControl
          label={t('sources.kind')}
          value={kind}
          onChange={setKind}
          options={[
            { value: 'out', label: t('sources.kind_out') },
            { value: 'in', label: t('sources.kind_in') },
            { value: 'ignore', label: t('sources.kind_ignore') },
          ]}
        />
      </Field>

      <Field
        label={t('sources.patternLabel')}
        htmlFor="rule-pattern"
        hint={t('sources.patternHint')}
        error={showErrors ? error : null}
        labelAside={
          <span className="flex gap-1">
            {tokens.map((token) => (
              <button
                key={token}
                type="button"
                onClick={() => insert(token)}
                className="rounded-md border px-1.5 py-0.5 font-mono text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                {token}
              </button>
            ))}
          </span>
        }
      >
        <Textarea
          id="rule-pattern"
          ref={textareaRef}
          rows={3}
          autoFocus={editing === 'new'}
          className="font-mono"
          placeholder={t(kind === 'ignore' ? 'sources.patternIgnore' : 'sources.pattern')}
          value={pattern}
          onChange={(e) => setPattern(e.target.value)}
        />
      </Field>

      <div className="space-y-2 rounded-lg border bg-muted/40 p-3">
        <p className={cn('text-sm', stat?.matches === 0 ? 'text-warning' : 'text-muted-foreground')}>
          {stat === undefined ? t('sources.ruleTryHint') : <RuleStatText stat={stat} />}
        </p>
        {examples.length > 0 && (
          <ul className="divide-y">
            {examples.map((notification) => (
              <li key={notification.id} className="space-y-1 py-2 last:pb-0">
                <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                  <span className="shrink-0">
                    {formatShortDate(notification.postedAt.slice(0, 10))} · {formatTime24h(notification.postedAt)}
                  </span>
                  <NotificationReadingBadge notification={{ ...notification, draft: null }} />
                </div>
                <p className="line-clamp-2 whitespace-pre-wrap font-mono text-xs">{[notification.title, notification.text].filter(Boolean).join('\n')}</p>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="flex flex-wrap justify-end gap-2 border-t pt-4 max-sm:[&>*]:flex-1">
        {onRemove && (
          <Button type="button" variant="ghost" className="text-destructive hover:bg-destructive/10 hover:text-destructive max-sm:basis-full sm:mr-auto" onClick={onRemove}>
            <Trash2 className="mr-2 h-4 w-4" />
            {t('sources.removeRule')}
          </Button>
        )}
        <Button type="button" variant="outline" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        <Button type="submit">{t('sources.ruleDone')}</Button>
      </div>
    </form>
  )
}
