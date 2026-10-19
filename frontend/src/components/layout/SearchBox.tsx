import { useEffect, useRef, useState } from 'react'
import { createSearchParams, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Command as CommandPrimitive } from 'cmdk'
import { Banknote, CornerDownLeft, Layers, Search, ShoppingCart, Wallet, type LucideIcon } from 'lucide-react'
import { Command, CommandGroup, CommandItem, CommandList } from '@/components/ui/command'
import Highlight from '@/components/Highlight'
import { useDebouncedValue, useSearchSuggestions } from '@/hooks/use-search'
import { searchTerms } from '@/lib/search'
import { cn } from '@/lib/utils'
import type { AccountSuggestion } from '@/types/search'

const accountIcons: Record<AccountSuggestion['type'], LucideIcon> = {
  asset: Wallet,
  expense: ShoppingCart,
  revenue: Banknote,
}

function isTyping(target: EventTarget | null): boolean {
  return target instanceof HTMLElement
    && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
}

/**
 * The search box in the header. Enter (or the first entry of its menu) opens
 * the transactions found on /search; the menu also offers the accounts and
 * instruments whose name matches, to open their page directly.
 * `/` or Ctrl+K puts the cursor in it from anywhere.
 *
 * On phones it opens from the top bar's magnifier: `autoFocus` brings up the
 * keyboard, and `onDone` closes it again once a page is opened.
 */
export default function SearchBox({ className, autoFocus, onDone }: { className?: string; autoFocus?: boolean; onDone?: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams] = useSearchParams()
  const inputRef = useRef<HTMLInputElement>(null)
  const [open, setOpen] = useState(false)

  // On the results page the box shows what was searched; elsewhere it starts empty.
  const urlQuery = location.pathname === '/search' ? searchParams.get('q') ?? '' : ''
  const [value, setValue] = useState(urlQuery)
  const [shownQuery, setShownQuery] = useState(urlQuery)
  if (shownQuery !== urlQuery) {
    setShownQuery(urlQuery)
    setValue(urlQuery)
  }

  const query = value.trim()
  const debouncedQuery = useDebouncedValue(query, 200)
  const { data: suggestions } = useSearchSuggestions(debouncedQuery)
  const terms = searchTerms(query)
  // Suggestions for an older text stay hidden until the new ones arrive.
  const accounts = debouncedQuery === query ? suggestions?.accounts ?? [] : []
  const instruments = debouncedQuery === query ? suggestions?.instruments ?? [] : []

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const shortcut = (event.key === 'k' && (event.metaKey || event.ctrlKey)) || (event.key === '/' && !isTyping(event.target))
      if (!shortcut) return
      event.preventDefault()
      inputRef.current?.focus()
      inputRef.current?.select()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  const close = () => {
    setOpen(false)
    inputRef.current?.blur()
  }

  const searchTransactions = () => {
    close()
    navigate({ pathname: '/search', search: createSearchParams({ q: query }).toString() })
    onDone?.()
  }

  const openPage = (path: string) => {
    close()
    setValue('')
    navigate(path)
    onDone?.()
  }

  return (
    <Command shouldFilter={false} loop className={cn('relative h-auto w-96 overflow-visible bg-transparent', className)}>
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <CommandPrimitive.Input
          ref={inputRef}
          autoFocus={autoFocus}
          enterKeyHint="search"
          value={value}
          onValueChange={(next) => {
            setValue(next)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          onBlur={() => setOpen(false)}
          onKeyDown={(event) => {
            if (event.key === 'Escape') close()
          }}
          placeholder={t('search.placeholder')}
          aria-label={t('search.placeholder')}
          className="flex h-10 w-full rounded-md border border-input bg-background py-2 pl-10 pr-10 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        />
        {!value && (
          <kbd className="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 md:block rounded border bg-muted px-1.5 font-mono text-[10px] text-muted-foreground">
            /
          </kbd>
        )}
      </div>

      {open && query !== '' && (
        <div
          className="absolute left-0 top-full z-50 mt-1 w-full overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md"
          // Clicking an entry must not blur the input (which closes the menu) before it is selected.
          onMouseDown={(event) => event.preventDefault()}
        >
          <CommandList className="max-h-[60vh]">
            <CommandGroup>
              <CommandItem value="__search__" onSelect={searchTransactions} className="gap-2">
                <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
                <span className="flex-1 truncate">{t('search.searchTransactions', { query })}</span>
                <CornerDownLeft className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
              </CommandItem>
            </CommandGroup>

            {accounts.length > 0 && (
              <CommandGroup heading={t('search.accounts')}>
                {accounts.map((account) => {
                  const Icon = accountIcons[account.type]
                  return (
                    <CommandItem
                      key={account.id}
                      value={`account:${account.id}`}
                      onSelect={() => openPage(`/accounts/${account.type}/${account.id}`)}
                      className="gap-2"
                    >
                      <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                      <span className="flex-1 truncate"><Highlight text={account.name} terms={terms} /></span>
                      <span className="text-xs text-muted-foreground">{t(`search.accountType_${account.type}`)}</span>
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            )}

            {instruments.length > 0 && (
              <CommandGroup heading={t('search.instruments')}>
                {instruments.map((instrument) => (
                  <CommandItem
                    key={instrument.id}
                    value={`instrument:${instrument.id}`}
                    onSelect={() => openPage(`/investments/instruments/${instrument.id}`)}
                    className="gap-2"
                  >
                    <Layers className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1 truncate"><Highlight text={instrument.name} terms={terms} /></span>
                    {instrument.ticker && (
                      <span className="text-xs text-muted-foreground"><Highlight text={instrument.ticker} terms={terms} /></span>
                    )}
                  </CommandItem>
                ))}
              </CommandGroup>
            )}
          </CommandList>
          <p className="border-t px-3 py-2 text-xs text-muted-foreground">{t('search.hint')}</p>
        </div>
      )}
    </Command>
  )
}
