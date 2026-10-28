import { useRef, useState, type PointerEvent } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { ChevronDown, type LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover'

interface NavLink {
  name: string
  href: string
  icon: LucideIcon
  /** When true, only an exact pathname match highlights this link (no prefix match for nested routes like /new or /:id/edit). */
  matchExact?: boolean
}

interface CollapsibleNavSectionProps {
  label: string
  icon: LucideIcon
  isOpen: boolean
  isActive: boolean
  onToggle: () => void
  links: NavLink[]
  /** Sidebar reduced to icons: the sub-pages open in a panel on the right instead of below. */
  collapsed?: boolean
}

/** Mouse leaving the icon towards the panel crosses a small gap: wait a moment before closing. */
const CLOSE_DELAY_MS = 150

function SubLinks({ links, className, onNavigate }: { links: NavLink[]; className?: string; onNavigate?: () => void }) {
  const location = useLocation()

  return (
    <div className={cn('space-y-1', className)}>
      {links.map((item) => {
        const isLinkActive = item.matchExact
          ? location.pathname === item.href
          : location.pathname === item.href || location.pathname.startsWith(`${item.href}/`)
        return (
          <Link
            key={item.name}
            to={item.href}
            onClick={onNavigate}
            className={cn(
              'relative flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-all duration-200',
              isLinkActive
                ? 'bg-accent text-primary'
                : 'text-muted-foreground hover:bg-accent/50 hover:text-accent-foreground'
            )}
          >
            {isLinkActive && (
              <div className="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-primary" />
            )}
            <item.icon className="h-4 w-4" />
            {item.name}
          </Link>
        )
      })}
    </div>
  )
}

/**
 * Collapsed sidebar: just the section's icon. Its sub-pages show in a panel
 * on the right when the mouse is over it, or on a tap/click (tablets, keyboard).
 */
function FlyoutNavSection({ label, icon: SectionIcon, isActive, links }: CollapsibleNavSectionProps) {
  const [open, setOpen] = useState(false)
  const anchorRef = useRef<HTMLButtonElement>(null)
  const closeTimer = useRef<number | undefined>(undefined)
  const lastPointerType = useRef<string>('')
  /** Only opened from the keyboard does the focus move into the panel. */
  const focusOnOpen = useRef(false)

  const openNow = () => {
    window.clearTimeout(closeTimer.current)
    setOpen(true)
  }
  const closeSoon = () => {
    window.clearTimeout(closeTimer.current)
    closeTimer.current = window.setTimeout(() => setOpen(false), CLOSE_DELAY_MS)
  }
  const onPointerEnter = (event: PointerEvent) => {
    if (event.pointerType !== 'mouse') return
    if (!open) focusOnOpen.current = false
    openNow()
  }
  const onPointerLeave = (event: PointerEvent) => {
    if (event.pointerType === 'mouse') closeSoon()
  }
  const close = () => {
    window.clearTimeout(closeTimer.current)
    setOpen(false)
  }

  return (
    <Popover open={open} onOpenChange={(next) => (next ? openNow() : close())}>
      <PopoverAnchor asChild>
        <button
          ref={anchorRef}
          type="button"
          aria-label={label}
          aria-expanded={open}
          onPointerDown={(event) => (lastPointerType.current = event.pointerType)}
          onPointerEnter={onPointerEnter}
          onPointerLeave={onPointerLeave}
          // The mouse already opened it by hovering, so a click keeps it open; a tap or a key toggles it.
          onClick={(event) => {
            const byKeyboard = event.detail === 0
            focusOnOpen.current = byKeyboard
            if (lastPointerType.current === 'mouse' && !byKeyboard) openNow()
            else setOpen((current) => !current)
          }}
          className={cn(
            'relative flex w-full items-center justify-center rounded-xl py-2.5 transition-all duration-200',
            isActive || open
              ? 'bg-accent text-primary'
              : 'text-muted-foreground hover:bg-accent/50 hover:text-accent-foreground'
          )}
        >
          {isActive && <div className="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-primary" />}
          <SectionIcon className="h-5 w-5" />
        </button>
      </PopoverAnchor>
      <PopoverContent
        side="right"
        align="start"
        sideOffset={10}
        className="w-56 rounded-xl p-1.5"
        onPointerEnter={onPointerEnter}
        onPointerLeave={onPointerLeave}
        // Opened by hovering or tapping: don't pull the focus away from the page.
        onOpenAutoFocus={(event) => !focusOnOpen.current && event.preventDefault()}
        // A tap on the icon itself is its own toggle, not a click outside.
        onInteractOutside={(event) => {
          if (anchorRef.current?.contains(event.target as Node)) event.preventDefault()
        }}
      >
        <div className="group-label px-3 pb-1.5 pt-1">
          {label}
        </div>
        <SubLinks links={links} onNavigate={close} />
      </PopoverContent>
    </Popover>
  )
}

export default function CollapsibleNavSection(props: CollapsibleNavSectionProps) {
  const { label, icon: SectionIcon, isOpen, isActive, onToggle, links, collapsed } = props

  if (collapsed) return <FlyoutNavSection {...props} />

  return (
    <div>
      <button
        type="button"
        onClick={onToggle}
        className={cn(
          'relative flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200',
          isActive
            ? 'bg-accent text-primary'
            : 'text-muted-foreground hover:bg-accent/50 hover:text-accent-foreground'
        )}
      >
        {isActive && (
          <div className="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-primary" />
        )}
        <SectionIcon className="h-5 w-5" />
        <span className="flex-1 whitespace-nowrap text-left">{label}</span>
        <ChevronDown
          className={cn('h-4 w-4 transition-transform duration-200', isOpen && 'rotate-180')}
        />
      </button>

      {isOpen && <SubLinks links={links} className="mt-1 pl-6" />}
    </div>
  )
}
