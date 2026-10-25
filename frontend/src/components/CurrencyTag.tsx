import { useAuth } from '@/contexts/AuthContext'

/** An account's own currency, named next to it when it isn't the user's (what is shown is converted into theirs). */
export default function CurrencyTag({ code }: { code?: string | null }) {
  const { user } = useAuth()
  if (!code || code === user?.currency) return null

  return <span className="ml-1.5 text-xs font-normal text-muted-foreground">{code}</span>
}
