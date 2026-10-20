import { useState, type ReactNode } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { MoreHorizontal, Pencil, Trash2, Plus, GripVertical, Wallet, LineChart, ShoppingCart, Banknote } from 'lucide-react'
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core'
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { restrictToVerticalAxis } from '@dnd-kit/modifiers'
import { CSS } from '@dnd-kit/utilities'
import PageHeader from '@/components/PageHeader'
import FilterBar from '@/components/FilterBar'
import PillGroup from '@/components/ui/pill-group'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import DateWithTime from '@/components/DateWithTime'
import Pagination from '@/components/Pagination'
import TableSkeleton from '@/components/TableSkeleton'
import { useAccounts, useHasAnyAccount, useMoveAccount } from '@/hooks/use-accounts'
import DeleteAccountDialog from '@/components/DeleteAccountDialog'
import { cn, formatDateTime, formatIban, formatMoney, moneyColorClass, totalAccountValue } from '@/lib/utils'
import type { Account, AccountStatusFilter, AccountType, AssetAccountKind, MoveAccountTarget } from '@/types/account'
import { format } from 'date-fns'
import EmptyValue from '@/components/EmptyValue'
import AfterTaxTooltip from '@/components/AfterTaxTooltip'
import { handleApiError } from '@/lib/error-handler'
import { MobileListCard, MobileListIcon, MobileListRow, MobileListSkeleton } from '@/components/MobileList'
import { useIsMobile } from '@/hooks/use-media-query'
import { useTimeframe } from '@/contexts/TimeframeContext'
import { useKeepPageInRange, useListPage } from '@/hooks/use-list-page'

type KindFilter = AssetAccountKind | 'all'

const STATUS_FILTERS: AccountStatusFilter[] = ['all', 'active', 'inactive']
const KIND_FILTERS: KindFilter[] = ['all', 'checking', 'investment']
/** Inactive (closed) accounts are hidden unless asked for. */
const DEFAULT_STATUS: AccountStatusFilter = 'active'

/** Phones: an account as a list row; edit and delete are on its page, the order is changed on a larger screen. */
function AccountListRow({ account, onClick }: { account: Account; onClick: () => void }) {
  const { t } = useTranslation()
  const icon =
    account.type === 'expense' ? (
      <MobileListIcon className="text-negative"><ShoppingCart /></MobileListIcon>
    ) : account.type === 'revenue' ? (
      <MobileListIcon className="text-positive"><Banknote /></MobileListIcon>
    ) : account.assetKind === 'investment' ? (
      <MobileListIcon className="text-invest"><LineChart /></MobileListIcon>
    ) : (
      <MobileListIcon className="text-info"><Wallet /></MobileListIcon>
    )
  const subtitle = [
    account.type === 'asset' && account.assetKind ? t(`accountsCommon.assetKind_${account.assetKind}`) : null,
    !account.isActive ? t('accountsCommon.status_inactive') : null,
    account.lastActivityAt ? formatDateTime(account.lastActivityAt) : null,
  ]
    .filter(Boolean)
    .join(' · ')

  return (
    <MobileListRow
      onClick={onClick}
      leading={icon}
      title={account.name}
      subtitle={subtitle || undefined}
      value={formatMoney(totalAccountValue(account), account.currencySymbol)}
      valueClassName={moneyColorClass(totalAccountValue(account))}
    />
  )
}

function pick<T extends string>(value: string | null, allowed: T[], fallback: T): T {
  return allowed.includes(value as T) ? (value as T) : fallback
}

interface AccountCellsProps {
  account: Account
  isAsset: boolean
  onEdit: () => void
  onDelete: () => void
}

/** The cells of one account's row, shared by the plain and the draggable row. */
function AccountCells({ account, isAsset, onEdit, onDelete }: AccountCellsProps) {
  const { t } = useTranslation()

  return (
    <>
      <TableCell className={cn('font-medium', isAsset && 'pl-1')}>{account.name}</TableCell>
      <TableCell className={cn('text-right tabular-nums', moneyColorClass(totalAccountValue(account)))}>
        <AfterTaxTooltip
          variant="value"
          amount={totalAccountValue(account)}
          estimatedTax={account.estimatedTax}
          currencySymbol={account.currencySymbol}
        >
          {formatMoney(totalAccountValue(account), account.currencySymbol)}
        </AfterTaxTooltip>
      </TableCell>
      <TableCell className="whitespace-nowrap tabular-nums text-muted-foreground">
        {account.iban ? formatIban(account.iban) : <EmptyValue />}
      </TableCell>
      {isAsset && (
        <TableCell className="text-muted-foreground">
          {account.assetKind ? t(`accountsCommon.assetKind_${account.assetKind}`) : <EmptyValue />}
        </TableCell>
      )}
      <TableCell>
        <Badge variant={account.isActive ? 'success' : 'secondary'}>
          {account.isActive ? t('common.yes') : t('common.no')}
        </Badge>
      </TableCell>
      <TableCell className="text-muted-foreground">
        {account.lastActivityAt ? <DateWithTime date={account.lastActivityAt} /> : <EmptyValue />}
      </TableCell>
      <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon">
              <MoreHorizontal className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={onEdit}>
              <Pencil className="mr-2 h-4 w-4" />
              {t('common.edit')}
            </DropdownMenuItem>
            <DropdownMenuItem
              className="text-destructive focus:text-destructive"
              onClick={onDelete}
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {t('common.delete')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </>
  )
}

/** A row the user can drag by its handle (keyboard too: focus the handle, Space, arrows, Space). */
function SortableAccountRow({ id, onClick, children }: { id: string; onClick: () => void; children: ReactNode }) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id })

  return (
    <TableRow
      ref={setNodeRef}
      style={{ transform: CSS.Translate.toString(transform), transition }}
      className={cn('group cursor-pointer', isDragging && 'relative z-10 bg-background shadow-lg')}
      onClick={onClick}
    >
      <TableCell className="w-0 pl-2 pr-0 [&_button:has(>svg:only-child)]:w-5" onClick={(e) => e.stopPropagation()}>
        <button
          ref={setActivatorNodeRef}
          type="button"
          {...attributes}
          {...listeners}
          aria-label={t('accountsCommon.dragToReorder')}
          title={t('accountsCommon.dragToReorder')}
          className="flex h-8 cursor-grab touch-none items-center justify-center rounded-md text-muted-foreground/40 transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing group-hover:text-muted-foreground"
        >
          <GripVertical className="h-4 w-4" />
        </button>
      </TableCell>
      {children}
    </TableRow>
  )
}

interface AccountsTableProps {
  type: AccountType
  title: string
  description: string
  emptyTitle: string
  emptyDescription: string
}

export default function AccountsTable({
  type,
  title,
  description,
  emptyTitle,
  emptyDescription,
}: AccountsTableProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const isAsset = type === 'asset'
  // Kept in the URL, so coming back to the list (or sharing the link) keeps the filters.
  const status = pick(searchParams.get('status'), STATUS_FILTERS, DEFAULT_STATUS)
  const kind: KindFilter = isAsset ? pick(searchParams.get('kind'), KIND_FILTERS, 'all') : 'all'
  const isFiltered = status !== 'all' || kind !== 'all'
  // Balances at the end of the selected period, like the dashboard's boxes.
  const { timeframe } = useTimeframe()
  const balanceDay = format(timeframe.to, 'yyyy-MM-dd')
  const { page, setPage } = useListPage([status, kind])
  const { data, isLoading, isPlaceholderData } = useAccounts(
    type,
    page,
    {
      status,
      kind: kind === 'all' ? undefined : kind,
    },
    balanceDay
  )
  const balanceHeading = t('accountsCommon.balanceOn', { date: formatDateTime(balanceDay) })
  useKeepPageInRange(page, setPage, isPlaceholderData ? undefined : data)
  const isMobile = useIsMobile()
  const moveAccount = useMoveAccount()
  // The order just dropped, shown until the list comes back from the server in that order.
  const [droppedOrder, setDroppedOrder] = useState<{ items: Account[]; of: typeof data } | null>(null)
  const accounts = droppedOrder && droppedOrder.of === data ? droppedOrder.items : data?.items
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )
  const isEmpty = !isLoading && (!accounts || accounts.length === 0)
  // Only asked when the filtered list is empty: no account at all means inviting to add the first one.
  const { data: hasAnyAccount, isLoading: isCheckingAny } = useHasAnyAccount(type, isEmpty && isFiltered)
  const showFilteredEmpty = isEmpty && isFiltered && hasAnyAccount === true
  const [accountToDelete, setAccountToDelete] = useState<Account | null>(null)

  const basePath = `/accounts/${type}`

  /** Sent as "after/before this neighbour", so it works on a filtered or paginated list too. */
  const handleDragEnd = ({ active, over }: DragEndEvent) => {
    if (!accounts || !over || active.id === over.id) return
    const from = accounts.findIndex((account) => account.id === active.id)
    const to = accounts.findIndex((account) => account.id === over.id)
    const reordered = arrayMove(accounts, from, to)
    const target: MoveAccountTarget = to > 0 ? { afterId: reordered[to - 1].id } : { beforeId: reordered[1].id }

    setDroppedOrder({ items: reordered, of: data })
    moveAccount.mutate(
      { id: String(active.id), target },
      {
        onError: (error) => {
          setDroppedOrder(null)
          toast.error(handleApiError(error))
        },
      }
    )
  }

  const setFilter = (key: 'status' | 'kind', value: string, defaultValue: string) => {
    const next = new URLSearchParams(searchParams)
    if (value === defaultValue) next.delete(key)
    else next.set(key, value)
    // The page goes back to the first through useListPage.
    setSearchParams(next, { replace: true })
  }

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader
        title={title}
        description={description}
        actions={
          // Phones add from the bottom bar's "+".
          <Button onClick={() => navigate(`${basePath}/new`)} className="hidden md:inline-flex">
            <Plus className="mr-2 h-4 w-4" />
            {t('accountsCommon.add')}
          </Button>
        }
      />

      <FilterBar
        end={
          <PillGroup
            label={t('accountsCommon.filterStatus')}
            options={STATUS_FILTERS.map((value) => ({
              value,
              label: t(value === 'all' ? 'accountsCommon.filterAll' : `accountsCommon.status_${value}`),
            }))}
            value={status}
            onChange={(value) => setFilter('status', value, DEFAULT_STATUS)}
          />
        }
      >
        {isAsset && (
          <PillGroup
            label={t('accountsCommon.assetKind')}
            options={KIND_FILTERS.map((value) => ({
              value,
              label: t(value === 'all' ? 'accountsCommon.filterAll' : `accountsCommon.kindFilter_${value}`),
            }))}
            value={kind}
            onChange={(value) => setFilter('kind', value, 'all')}
          />
        )}
      </FilterBar>

      {isMobile ? (
        isLoading || (isEmpty && isFiltered && isCheckingAny) ? (
          <MobileListSkeleton rows={4} />
        ) : !accounts || accounts.length === 0 ? (
          <MobileListCard>
            <p className="px-4 py-10 text-center text-sm text-muted-foreground">
              {showFilteredEmpty ? t('accountsCommon.noFilteredTitle') : emptyTitle}
            </p>
          </MobileListCard>
        ) : (
          <MobileListCard>
            {accounts.map((account) => (
              <AccountListRow key={account.id} account={account} onClick={() => navigate(`${basePath}/${account.id}`)} />
            ))}
            {data && data.total > data.pageSize && (
              <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPageChange={setPage} />
            )}
          </MobileListCard>
        )
      ) : (
        <Card>
          <CardContent className="p-0">
            {isLoading || (isEmpty && isFiltered && isCheckingAny) ? (
              <Table>
                <TableHeader>
                  <TableRow>
                    {isAsset && <TableHead className="w-0 pl-2 pr-0" />}
                    <TableHead className={cn(isAsset && 'pl-1')}>{t('accountsCommon.name')}</TableHead>
                    <TableHead className="text-right">{balanceHeading}</TableHead>
                    <TableHead>{t('accountsCommon.iban')}</TableHead>
                    {isAsset && <TableHead>{t('accountsCommon.assetKind')}</TableHead>}
                    <TableHead>{t('accountsCommon.active')}</TableHead>
                    <TableHead>{t('accountsCommon.lastActivity')}</TableHead>
                    <TableHead className="text-right">{t('accountsCommon.actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableSkeleton
                  columns={[
                    ...(isAsset ? [{ cellClassName: 'w-0 pl-2 pr-0', skeletonClassName: 'h-4 w-5' }] : []),
                    { cellClassName: cn(isAsset && 'pl-1'), skeletonClassName: 'w-40' },
                    { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-20' },
                    { skeletonClassName: 'w-28' },
                    ...(isAsset ? [{ skeletonClassName: 'w-24' }] : []),
                    { skeletonClassName: 'h-5 w-14 rounded-full' },
                    { skeletonClassName: 'w-28' },
                    { cellClassName: 'text-right', skeletonClassName: 'ml-auto h-8 w-8 rounded-full' },
                  ]}
                />
              </Table>
            ) : showFilteredEmpty ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <h3 className="text-lg font-semibold mb-2">{t('accountsCommon.noFilteredTitle')}</h3>
                <p className="text-sm text-muted-foreground max-w-sm">{t('accountsCommon.noFilteredDescription')}</p>
              </div>
            ) : !accounts || accounts.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <div className="rounded-full bg-muted p-4 mb-4">
                  <Plus className="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 className="text-lg font-semibold mb-2">{emptyTitle}</h3>
                <p className="text-sm text-muted-foreground mb-4 max-w-sm">
                  {emptyDescription}
                </p>
                <Button onClick={() => navigate(`${basePath}/new`)}>
                  <Plus className="mr-2 h-4 w-4" />
                  {t('accountsCommon.add')}
                </Button>
              </div>
            ) : (
              <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                modifiers={[restrictToVerticalAxis]}
                onDragEnd={handleDragEnd}
              >
                <Table>
                  <TableHeader>
                    <TableRow>
                      {isAsset && <TableHead className="w-0 pl-2 pr-0" />}
                      <TableHead className={cn(isAsset && 'pl-1')}>{t('accountsCommon.name')}</TableHead>
                      <TableHead className="text-right">{balanceHeading}</TableHead>
                      <TableHead>{t('accountsCommon.iban')}</TableHead>
                      {isAsset && <TableHead>{t('accountsCommon.assetKind')}</TableHead>}
                      <TableHead>{t('accountsCommon.active')}</TableHead>
                      <TableHead>{t('accountsCommon.lastActivity')}</TableHead>
                      <TableHead className="text-right">{t('accountsCommon.actions')}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    <SortableContext
                      items={accounts.map((account) => account.id)}
                      strategy={verticalListSortingStrategy}
                      disabled={!isAsset || moveAccount.isPending}
                    >
                      {accounts.map((account) => {
                        const open = () => navigate(`${basePath}/${account.id}`)
                        const cells = (
                          <AccountCells
                            account={account}
                            isAsset={isAsset}
                            onEdit={() => navigate(`${basePath}/${account.id}/edit`)}
                            onDelete={() => setAccountToDelete(account)}
                          />
                        )

                        return isAsset ? (
                          <SortableAccountRow key={account.id} id={account.id} onClick={open}>
                            {cells}
                          </SortableAccountRow>
                        ) : (
                          <TableRow key={account.id} className="cursor-pointer" onClick={open}>
                            {cells}
                          </TableRow>
                        )
                      })}
                    </SortableContext>
                  </TableBody>
                </Table>
              </DndContext>
            )}
          </CardContent>
          {data && (
            <Pagination
              page={data.page}
              pageSize={data.pageSize}
              total={data.total}
              onPageChange={setPage}
            />
          )}
        </Card>
      )}

      <DeleteAccountDialog account={accountToDelete} onClose={() => setAccountToDelete(null)} />
    </div>
  )
}
