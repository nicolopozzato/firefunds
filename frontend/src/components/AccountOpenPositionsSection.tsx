import { useTranslation } from 'react-i18next'
import { Skeleton } from '@/components/ui/skeleton'
import OpenPositionCard from '@/components/OpenPositionCard'
import { useAccountOpenPositions } from '@/hooks/use-accounts'

interface AccountOpenPositionsSectionProps {
  accountId: string
  from: string
  to: string
  currencySymbol: string | null
}

export default function AccountOpenPositionsSection({ accountId, from, to, currencySymbol }: AccountOpenPositionsSectionProps) {
  const { t } = useTranslation()
  const { data: positions, isLoading } = useAccountOpenPositions(accountId, from, to)

  if (isLoading) {
    return (
      <div className="space-y-3">
        <h2 className="text-lg font-semibold">{t('accountDetail.openPositions')}</h2>
        <div className="space-y-3">
          <Skeleton className="h-56 w-full rounded-xl" />
          <Skeleton className="h-56 w-full rounded-xl" />
        </div>
      </div>
    )
  }

  if (!positions || positions.length === 0) {
    return null
  }

  // Share of the account's invested value; positions without a price yet can't be weighed.
  const totalValue = positions.reduce((sum, p) => sum + (p.currentValue !== null ? Number(p.currentValue) : 0), 0)

  return (
    <div className="space-y-3">
      <h2 className="text-lg font-semibold">{t('accountDetail.openPositions')}</h2>
      <div className="space-y-3">
        {positions.map((position) => (
          <OpenPositionCard
            key={position.instrumentId}
            position={position}
            currencySymbol={currencySymbol}
            weight={position.currentValue !== null && totalValue > 0 ? (Number(position.currentValue) / totalValue) * 100 : null}
            weightLabel={t('accountDetail.positionWeight')}
          />
        ))}
      </div>
    </div>
  )
}
