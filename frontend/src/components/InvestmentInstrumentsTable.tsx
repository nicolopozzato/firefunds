import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { MoreHorizontal, Pencil, Trash2, Plus } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
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
import Pagination from '@/components/Pagination'
import TableSkeleton from '@/components/TableSkeleton'
import { useInvestmentInstruments } from '@/hooks/use-investments'
import DeleteInstrumentDialog from '@/components/DeleteInstrumentDialog'
import type { InvestmentInstrument } from '@/types/investment'
import EmptyValue from '@/components/EmptyValue'
import { useAuth } from '@/contexts/AuthContext'
import { formatRate } from '@/lib/capital-gains-tax'
import { InvestmentSectionTabs } from '@/components/layout/SectionTabs'
import { MobileListCard, MobileListRow, MobileListSkeleton } from '@/components/MobileList'
import { useIsMobile } from '@/hooks/use-media-query'
import { useKeepPageInRange, useListPage } from '@/hooks/use-list-page'
import EmptyState from '@/components/EmptyState'

const basePath = '/investments/instruments'

export default function InvestmentInstrumentsTable() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const defaultTaxRate = user?.capitalGainsTaxRate ?? '26'
  const navigate = useNavigate()
  const isMobile = useIsMobile()
  const { page, setPage } = useListPage()
  const { data, isLoading, isPlaceholderData } = useInvestmentInstruments(page)
  useKeepPageInRange(page, setPage, isPlaceholderData ? undefined : data)
  const instruments = data?.items
  const [instrumentToDelete, setInstrumentToDelete] = useState<InvestmentInstrument | null>(null)

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader
        title={t('investmentInstruments.title')}
        description={t('investmentInstruments.description')}
        actions={
          // Phones add from the bottom bar's "+".
          <Button onClick={() => navigate(`${basePath}/new`)} className="hidden md:inline-flex">
            <Plus className="mr-2 h-4 w-4" />
            {t('investmentInstruments.add')}
          </Button>
        }
      />

      <InvestmentSectionTabs />

      {/* Phones: a list, each instrument opening its page (where edit and delete are). */}
      {isMobile ? (
        isLoading ? (
          <MobileListSkeleton />
        ) : !instruments || instruments.length === 0 ? (
          <MobileListCard>
            <p className="px-4 py-10 text-center text-sm text-muted-foreground">{t('investmentInstruments.empty')}</p>
          </MobileListCard>
        ) : (
          <MobileListCard>
            {instruments.map((instrument) => (
              <MobileListRow
                key={instrument.id}
                onClick={() => navigate(`${basePath}/${instrument.id}`)}
                title={instrument.name}
                subtitle={[t(`investmentInstruments.type_${instrument.type}`), instrument.ticker, instrument.isin].filter(Boolean).join(' · ')}
                chevron
              />
            ))}
            {data && data.total > data.pageSize && (
              <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPageChange={setPage} />
            )}
          </MobileListCard>
        )
      ) : (
        <Card className="overflow-hidden">
          <CardContent className="p-0 sm:p-0">
            {isLoading ? (
              <Table className="table-fixed">
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('investmentInstruments.name')}</TableHead>
                    <TableHead className="w-28">{t('investmentInstruments.type')}</TableHead>
                    <TableHead className="w-28">{t('investmentInstruments.ticker')}</TableHead>
                    <TableHead className="w-36">{t('investmentInstruments.isin')}</TableHead>
                    <TableHead className="w-28 text-right">{t('investmentInstruments.taxColumn')}</TableHead>
                    <TableHead className="w-16"><span className="sr-only">{t('accountsCommon.actions')}</span></TableHead>
                  </TableRow>
                </TableHeader>
                <TableSkeleton
                  columns={[
                    { skeletonClassName: 'w-40' },
                    { skeletonClassName: 'h-5 w-20 rounded-full' },
                    { skeletonClassName: 'w-20' },
                    { skeletonClassName: 'w-28' },
                    { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-12' },
                    { cellClassName: 'text-right', skeletonClassName: 'ml-auto h-8 w-8 rounded-full' },
                  ]}
                />
              </Table>
            ) : !instruments || instruments.length === 0 ? (
              <EmptyState title={t('investmentInstruments.empty')} description={t('investmentInstruments.emptyDescription')} action={{ label: t('investmentInstruments.add'), onClick: () => navigate(`${basePath}/new`) }} />
            ) : (
              <Table className="table-fixed">
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('investmentInstruments.name')}</TableHead>
                    <TableHead className="w-28">{t('investmentInstruments.type')}</TableHead>
                    <TableHead className="w-28">{t('investmentInstruments.ticker')}</TableHead>
                    <TableHead className="w-36">{t('investmentInstruments.isin')}</TableHead>
                    <TableHead className="w-28 text-right">{t('investmentInstruments.taxColumn')}</TableHead>
                    <TableHead className="w-16"><span className="sr-only">{t('accountsCommon.actions')}</span></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {instruments.map((instrument) => (
                    <TableRow
                      key={instrument.id}
                      className="cursor-pointer"
                      onClick={() => navigate(`${basePath}/${instrument.id}`)}
                    >
                      <TableCell className="font-medium">{instrument.name}</TableCell>
                      <TableCell>
                        <Badge variant="outline">{t(`investmentInstruments.type_${instrument.type}`)}</Badge>
                      </TableCell>
                      <TableCell className="text-muted-foreground">{instrument.ticker ?? <EmptyValue />}</TableCell>
                      <TableCell className="font-mono text-sm text-muted-foreground">
                        {instrument.isin ?? <EmptyValue />}
                      </TableCell>
                      {/* Own rate in plain text; otherwise the preferences' rate, in brackets and faded. */}
                      <TableCell className="text-right tabular-nums">
                        {instrument.capitalGainsTaxRate !== null ? (
                          formatRate(instrument.capitalGainsTaxRate)
                        ) : (
                          <span className="text-muted-foreground/60">({formatRate(defaultTaxRate)})</span>
                        )}
                      </TableCell>
                      <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" aria-label={t('common.moreActions')}>
                              <MoreHorizontal className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => navigate(`${basePath}/${instrument.id}/edit`)}>
                              <Pencil className="mr-2 h-4 w-4" />
                              {t('common.edit')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              className="text-destructive focus:text-destructive"
                              onClick={() => setInstrumentToDelete(instrument)}
                            >
                              <Trash2 className="mr-2 h-4 w-4" />
                              {t('common.delete')}
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
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

      <DeleteInstrumentDialog instrument={instrumentToDelete} onClose={() => setInstrumentToDelete(null)} />
    </div>
  )
}
