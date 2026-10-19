import { useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, ChevronsUpDown, Plus } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useIsMobile } from '@/hooks/use-media-query'

export interface ComboboxOption {
  value: string
  label: string
  sublabel?: string
}

interface ComboboxProps {
  options: ComboboxOption[]
  value: string
  onChange: (value: string) => void
  /** Called when the user picks "create new" — must not persist anything itself. */
  onCreate?: (name: string) => void
  placeholder?: string
  /** Picking the selected option again clears the field (for optional fields). */
  clearable?: boolean
}

export default function Combobox({
  options,
  value,
  onChange,
  onCreate,
  placeholder,
  clearable = false,
}: ComboboxProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const pendingKeyRef = useRef<string | null>(null)

  const handleInputFocus = () => {
    if (pendingKeyRef.current !== null) {
      setSearch(pendingKeyRef.current)
      pendingKeyRef.current = null
    }
  }

  const selected = useMemo(() => options.find((option) => option.value === value), [options, value])

  const filtered = useMemo(() => {
    if (!search) return options
    const query = search.toLowerCase()
    return options.filter((option) => option.label.toLowerCase().includes(query))
  }, [options, search])

  const exactMatch = filtered.some((option) => option.label.toLowerCase() === search.trim().toLowerCase())
  const canCreate = !!onCreate && search.trim().length > 0 && !exactMatch

  const handleCreate = () => {
    if (!onCreate) return
    onCreate(search.trim())
    setSearch('')
    setOpen(false)
  }

  const handleTriggerKeyDown = (event: React.KeyboardEvent<HTMLButtonElement>) => {
    if (open || event.ctrlKey || event.metaKey || event.altKey) return
    if (event.key.length !== 1) return

    event.preventDefault()
    pendingKeyRef.current = event.key
    setOpen(true)
  }

  const trigger = (
    <Button
      type="button"
      variant="outline"
      role="combobox"
      aria-expanded={open}
      className="w-full justify-between font-normal"
      onKeyDown={handleTriggerKeyDown}
      onClick={isMobile ? () => setOpen(true) : undefined}
    >
      <span className={cn('truncate', !selected && 'text-muted-foreground')}>
        {selected ? selected.label : placeholder ?? t('combobox.placeholder')}
      </span>
      <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
    </Button>
  )

  const panel = (
    <Command shouldFilter={false} className={cn(isMobile && 'bg-transparent [&_[cmdk-item]]:py-3 [&_[cmdk-list]]:max-h-none')}>
      <CommandInput
        placeholder={t('combobox.search')}
        value={search}
        onValueChange={setSearch}
        onFocus={handleInputFocus}
      />
      <CommandList>
        {filtered.length === 0 && !canCreate && (
          <CommandEmpty>{t('combobox.noResults')}</CommandEmpty>
        )}
        <CommandGroup>
          {filtered.map((option) => (
            <CommandItem
              key={option.value}
              value={option.value}
              onSelect={() => {
                onChange(clearable && value === option.value ? '' : option.value)
                setSearch('')
                setOpen(false)
              }}
            >
              <Check
                className={cn('mr-2 h-4 w-4', value === option.value ? 'opacity-100' : 'opacity-0')}
              />
              <div className="flex flex-col">
                <span>{option.label}</span>
                {option.sublabel && (
                  <span className="text-xs text-muted-foreground">{option.sublabel}</span>
                )}
              </div>
            </CommandItem>
          ))}
          {canCreate && (
            <CommandItem value={`__create__${search}`} onSelect={handleCreate}>
              <Plus className="mr-2 h-4 w-4" />
              {t('combobox.create', { name: search.trim() })}
            </CommandItem>
          )}
        </CommandGroup>
      </CommandList>
    </Command>
  )

  // Phones: the choices in a panel from the bottom, where the keyboard can't
  // cover them; the search field gets the focus only when tapped.
  if (isMobile) {
    return (
      <>
        {trigger}
        <Drawer open={open} onOpenChange={setOpen}>
          <DrawerContent className="h-[85dvh]" onOpenAutoFocus={(event) => event.preventDefault()}>
            <DrawerHeader>
              <DrawerTitle>{placeholder ?? t('combobox.placeholder')}</DrawerTitle>
              <DrawerDescription className="sr-only">{t('combobox.search')}</DrawerDescription>
            </DrawerHeader>
            <div className="min-h-0 flex-1 overflow-y-auto px-2">{panel}</div>
          </DrawerContent>
        </Drawer>
      </>
    )
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>{trigger}</PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        {panel}
      </PopoverContent>
    </Popover>
  )
}
