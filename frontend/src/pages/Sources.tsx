import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Smartphone } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import EmptyState from '@/components/EmptyState'
import { MobileListCard, MobileListIcon, MobileListRow, MobileListSkeleton } from '@/components/MobileList'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { sourcesApi } from '@/lib/sources-api'
import { formatShortDate } from '@/lib/utils'

/** The apps whose notifications the phone sends: one per app, opened to set its account and how to read it. */
export default function Sources() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { data: sources, isLoading } = useQuery({ queryKey: ['sources'], queryFn: () => sourcesApi.list() })

  return (
    <div className="max-w-3xl space-y-4 md:space-y-6">
      <PageHeader title={t('sources.title')} description={t('sources.description')} />
      {isLoading ? (
        <MobileListSkeleton rows={3} />
      ) : !sources || sources.length === 0 ? (
        <Card>
          <EmptyState icon={Smartphone} title={t('sources.emptyTitle')} description={t('sources.emptyDescription')} action={{ label: t('sources.getApp'), onClick: () => navigate('/preferences?tab=api') }} />
        </Card>
      ) : (
        <MobileListCard>
          {sources.map((source) => (
            <MobileListRow
              key={source.id}
              chevron
              onClick={() => navigate(`/sources/${source.id}`)}
              leading={
                <MobileListIcon className="text-muted-foreground">
                  <Smartphone />
                </MobileListIcon>
              }
              title={
                <span className="flex items-center gap-2">
                  <span className="truncate">{source.appName}</span>
                  {!!source.unread && <Badge variant="warning" className="shrink-0 font-normal">{t('sources.unread', { count: source.unread })}</Badge>}
                </span>
              }
              subtitle={[
                t('sources.count', { count: source.total ?? 0 }),
                source.latest ? t('sources.latest', { date: formatShortDate(source.latest.slice(0, 10)) }) : null,
                source.accountId ? null : t('sources.noAccount'),
              ].filter(Boolean).join(' · ')}
            />
          ))}
        </MobileListCard>
      )}
    </div>
  )
}
