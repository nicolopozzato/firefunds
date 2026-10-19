import * as React from 'react'
import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import { cn } from '@/lib/utils'

const TooltipProvider = TooltipPrimitive.Provider

/** Lets a trigger open its tooltip on a tap (see `tapToOpen`). */
const TooltipOpenContext = React.createContext<((open: boolean) => void) | null>(null)

/**
 * Radix only opens a tooltip on hover or keyboard focus, never on a tap, so
 * on a tablet it stays hidden. The open state lives here, so a trigger with
 * `tapToOpen` can open it; a tap anywhere else closes it.
 */
function Tooltip({ open: openProp, defaultOpen, onOpenChange, ...props }: React.ComponentPropsWithoutRef<typeof TooltipPrimitive.Root>) {
  const [uncontrolledOpen, setUncontrolledOpen] = React.useState(defaultOpen ?? false)
  const open = openProp ?? uncontrolledOpen
  const setOpen = React.useCallback(
    (next: boolean) => {
      setUncontrolledOpen(next)
      onOpenChange?.(next)
    },
    [onOpenChange]
  )

  return (
    <TooltipOpenContext.Provider value={setOpen}>
      <TooltipPrimitive.Root open={open} onOpenChange={setOpen} {...props} />
    </TooltipOpenContext.Provider>
  )
}

const TooltipTrigger = React.forwardRef<
  React.ElementRef<typeof TooltipPrimitive.Trigger>,
  React.ComponentPropsWithoutRef<typeof TooltipPrimitive.Trigger> & {
    /**
     * A tap (touch or pen) opens the tooltip instead of going through to what
     * is underneath, e.g. a clickable table row. Mouse clicks are untouched.
     * Only for triggers that do nothing on their own when clicked.
     */
    tapToOpen?: boolean
  }
>(({ tapToOpen = false, onPointerDown, onClick, ...props }, ref) => {
  const setOpen = React.useContext(TooltipOpenContext)
  const lastPointerType = React.useRef('')

  return (
    <TooltipPrimitive.Trigger
      ref={ref}
      onPointerDown={(event) => {
        lastPointerType.current = event.pointerType
        onPointerDown?.(event)
      }}
      onClick={(event) => {
        onClick?.(event)
        if (tapToOpen && setOpen && lastPointerType.current !== 'mouse' && lastPointerType.current !== '') {
          // Keeps Radix from closing it on click, and the row from navigating.
          event.preventDefault()
          event.stopPropagation()
          setOpen(true)
        }
      }}
      {...props}
    />
  )
})
TooltipTrigger.displayName = TooltipPrimitive.Trigger.displayName

const TooltipContent = React.forwardRef<
  React.ElementRef<typeof TooltipPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof TooltipPrimitive.Content>
>(({ className, sideOffset = 4, ...props }, ref) => (
  <TooltipPrimitive.Portal>
    <TooltipPrimitive.Content
      ref={ref}
      sideOffset={sideOffset}
      className={cn(
        'z-50 overflow-hidden rounded-md bg-popover px-3 py-1.5 text-sm text-popover-foreground shadow-md border animate-in fade-in-0 zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
        className
      )}
      {...props}
    />
  </TooltipPrimitive.Portal>
))
TooltipContent.displayName = TooltipPrimitive.Content.displayName

export { Tooltip, TooltipTrigger, TooltipContent, TooltipProvider }
