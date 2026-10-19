import { useTranslation } from 'react-i18next'
import SegmentedControl from '@/components/ui/segmented-control'

export type PricePeriod = '15d' | '1m' | '6m' | 'ytd' | '1y' | '5y' | 'all'

const PERIODS: PricePeriod[] = ['15d', '1m', '6m', 'ytd', '1y', '5y', 'all']

interface PricePeriodSelectorProps {
  value: PricePeriod
  onChange: (period: PricePeriod) => void
}

export default function PricePeriodSelector({ value, onChange }: PricePeriodSelectorProps) {
  const { t } = useTranslation()

  return (
    <SegmentedControl
      label={t('pricePeriod.label')}
      options={PERIODS.map((period) => ({ value: period, label: t(`pricePeriod.${period}`) }))}
      value={value}
      onChange={onChange}
    />
  )
}
