import { useEffect, useState } from 'react'

/** The part of the page the on-screen keyboard leaves visible, as the visual viewport reports it. */
export interface KeyboardViewport {
  /** Distance of its top edge from the top of the page's own (layout) viewport. */
  top: number
  height: number
}

/** Below this much hidden, it's browser chrome (an address bar sliding away), not a keyboard. */
const KEYBOARD_MIN_HEIGHT = 120

/**
 * The visible area while a keyboard is open, null otherwise. Phone browsers
 * shrink only the *visual* viewport when the keyboard opens: a panel fixed to
 * the bottom of the layout viewport (a drawer) stays where it was, behind the
 * keyboard, and the browser shoves the page up to show the focused field.
 */
export function useKeyboardViewport(active: boolean): KeyboardViewport | null {
  const [viewport, setViewport] = useState<KeyboardViewport | null>(null)

  useEffect(() => {
    const visual = window.visualViewport
    if (!active || !visual) return

    const update = () => {
      const hidden = window.innerHeight - visual.height - visual.offsetTop
      const keyboardOpen = window.innerHeight - visual.height > KEYBOARD_MIN_HEIGHT && hidden >= 0
      setViewport((current) => {
        if (!keyboardOpen) return current === null ? current : null
        if (current && current.top === visual.offsetTop && current.height === visual.height) return current
        return { top: visual.offsetTop, height: visual.height }
      })
    }

    update()
    visual.addEventListener('resize', update)
    visual.addEventListener('scroll', update)
    return () => {
      visual.removeEventListener('resize', update)
      visual.removeEventListener('scroll', update)
      setViewport(null)
    }
  }, [active])

  return viewport
}
