import type { ReactNode } from 'react'
import { useKeyboardViewport } from '@/hooks/use-keyboard-viewport'
import { useIsMobile } from '@/hooks/use-media-query'
import { cn } from '@/lib/utils'

/**
 * A form's Cancel/Save buttons: at the end of the form on larger screens; on
 * phones a bar fixed at the bottom of the screen (the navigation bar is hidden
 * on form pages), so Save is always one tap away, above the keyboard too.
 */
export default function FormActions({ children }: { children: ReactNode }) {
  const isMobile = useIsMobile()
  // Phone browsers leave a bar fixed to the bottom behind the keyboard: lift it to the keyboard's top edge.
  const keyboard = useKeyboardViewport(isMobile)
  const style = keyboard ? { bottom: window.innerHeight - keyboard.top - keyboard.height } : undefined

  return (
    <div
      style={style}
      className={cn(
        'flex justify-end gap-2 max-md:fixed max-md:inset-x-0 max-md:bottom-0 max-md:z-30 max-md:glass-bar max-md:glass-bar-bottom max-md:px-4 max-md:pt-3 max-md:[&>*]:h-11 max-md:[&>*]:flex-1',
        keyboard ? 'max-md:pb-3' : 'max-md:pb-[calc(env(safe-area-inset-bottom)+0.75rem)]'
      )}
    >
      {children}
    </div>
  )
}
