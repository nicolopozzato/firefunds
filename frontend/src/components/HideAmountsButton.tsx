import { Eye, EyeOff } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/button'
import { useHideAmounts } from '@/hooks/use-hide-amounts'
import { cn } from '@/lib/utils'

/** Hides or shows every amount on this device (header, phones' top bar). */
export default function HideAmountsButton({ className }: { className?: string }) {
  const { t } = useTranslation()
  const [hidden, setHidden] = useHideAmounts()
  const label = hidden ? t('preferences.showAmounts') : t('preferences.hideAmounts')

  return (
    <Button
      variant="ghost"
      size="icon"
      className={cn(hidden && 'text-primary', className)}
      onClick={() => setHidden(!hidden)}
      aria-label={label}
      aria-pressed={hidden}
      title={label}
    >
      {hidden ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
    </Button>
  )
}
