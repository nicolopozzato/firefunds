import { useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command'
import { Button } from '@/components/ui/button'

export interface TagsInputOption {
  id: string
  name: string
}

interface TagsInputProps {
  options: TagsInputOption[]
  value: string[]
  onChange: (ids: string[]) => void
  /** Called when the user picks "create new" — must not persist anything itself. */
  onCreate?: (name: string) => void
  placeholder?: string
}

export default function TagsInput({
  options,
  value,
  onChange,
  onCreate,
  placeholder,
}: TagsInputProps) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const pendingKeyRef = useRef<string | null>(null)

  const handleInputFocus = () => {
    if (pendingKeyRef.current !== null) {
      setSearch(pendingKeyRef.current)
      pendingKeyRef.current = null
    }
  }

  const selectedOptions = useMemo(
    () => value.map((id) => options.find((option) => option.id === id)).filter((o): o is TagsInputOption => !!o),
    [options, value]
  )

  const filtered = useMemo(() => {
    const query = search.toLowerCase()
    return options.filter(
      (option) => !value.includes(option.id) && (!query || option.name.toLowerCase().includes(query))
    )
  }, [options, value, search])

  const exactMatch = options.some((option) => option.name.toLowerCase() === search.trim().toLowerCase())
  const canCreate = !!onCreate && search.trim().length > 0 && !exactMatch

  const toggle = (id: string) => {
    onChange(value.includes(id) ? value.filter((v) => v !== id) : [...value, id])
  }

  const remove = (id: string) => {
    onChange(value.filter((v) => v !== id))
  }

  const handleCreate = () => {
    if (!onCreate) return
    onCreate(search.trim())
    setSearch('')
  }

  const handleTriggerKeyDown = (event: React.KeyboardEvent<HTMLButtonElement>) => {
    if (open || event.ctrlKey || event.metaKey || event.altKey) return
    if (event.key.length !== 1) return

    event.preventDefault()
    pendingKeyRef.current = event.key
    setOpen(true)
  }

  return (
    <div className="space-y-2">
      {selectedOptions.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {selectedOptions.map((option) => (
            <Badge key={option.id} variant="secondary" className="gap-1 pr-1">
              {option.name}
              <button
                type="button"
                onClick={() => remove(option.id)}
                className="rounded-full p-0.5 hover:bg-muted-foreground/20"
              >
                <X className="h-3 w-3" />
              </button>
            </Badge>
          ))}
        </div>
      )}

      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            className="w-full justify-start font-normal text-muted-foreground"
            onKeyDown={handleTriggerKeyDown}
          >
            <Plus className="mr-2 h-4 w-4" />
            {placeholder ?? t('tagsInput.add')}
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
          <Command shouldFilter={false}>
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
                  <CommandItem key={option.id} value={option.id} onSelect={() => toggle(option.id)}>
                    {option.name}
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
        </PopoverContent>
      </Popover>
    </div>
  )
}
