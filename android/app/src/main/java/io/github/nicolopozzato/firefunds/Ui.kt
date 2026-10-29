package io.github.nicolopozzato.firefunds

import android.content.Context
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.Drawable
import android.graphics.drawable.GradientDrawable
import android.text.format.DateUtils
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.Switch
import android.widget.TextView

/** The few pieces both screens are built of, in code: cards, status lines, app rows. Colours as the web app's. */
class Ui(private val context: Context) {
    private val night = context.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK == Configuration.UI_MODE_NIGHT_YES

    val background = color("#F6F7F9", "#0C0C0E")
    private val surface = color("#FFFFFF", "#18181B")
    private val border = color("#E4E7EC", "#27272A")
    private val ink = color("#111827", "#F4F4F5")
    private val mutedInk = color("#6B7280", "#A1A1AA")
    val positive = color("#16A34A", "#22C55E")
    val negative = color("#DC2626", "#F87171")
    val warning = color("#D97706", "#FBBF24")
    val idle = color("#9CA3AF", "#52525B")

    fun dp(value: Int) = (value * context.resources.displayMetrics.density).toInt()

    fun card(): LinearLayout = LinearLayout(context).apply {
        orientation = LinearLayout.VERTICAL
        setPadding(dp(16), dp(8), dp(16), dp(8))
        background = GradientDrawable().apply {
            setColor(surface)
            setStroke(dp(1), border)
            cornerRadius = dp(12).toFloat()
        }
        layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
            bottomMargin = dp(16)
        }
    }

    fun divider(): View = View(context).apply {
        setBackgroundColor(border)
        layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(1)).apply {
            topMargin = dp(4)
            bottomMargin = dp(4)
        }
    }

    fun heading(value: String) = TextView(context).apply {
        text = value
        setTextColor(ink)
        setTextSize(TypedValue.COMPLEX_UNIT_SP, 18f)
        typeface = Typeface.DEFAULT_BOLD
        setPadding(dp(4), dp(8), dp(4), dp(8))
    }

    fun title(value: String) = TextView(context).apply {
        text = value
        setTextColor(ink)
        setTextSize(TypedValue.COMPLEX_UNIT_SP, 16f)
    }

    fun secondary(value: String) = TextView(context).apply {
        text = value
        setTextColor(mutedInk)
        setTextSize(TypedValue.COMPLEX_UNIT_SP, 14f)
    }

    /** A coloured dot, a title and what it means; its detail can be changed later. */
    inner class StatusLine(name: String) {
        val dot = View(context)
        val detail = secondary("")
        val view = LinearLayout(context).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding(0, dp(10), 0, dp(10))
            addView(dot, LinearLayout.LayoutParams(dp(10), dp(10)).apply {
                topMargin = dp(7)
                rightMargin = dp(12)
            })
            addView(LinearLayout(context).apply {
                orientation = LinearLayout.VERTICAL
                addView(title(name).apply { typeface = Typeface.DEFAULT_BOLD })
                addView(detail)
            }, LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
        }

        fun set(color: Int, value: String) {
            dot.background = GradientDrawable().apply {
                shape = GradientDrawable.OVAL
                setColor(color)
            }
            detail.text = value
        }
    }

    /** An app with its icon, a line under its name and a switch; the whole row flips it. */
    fun appRow(icon: Drawable?, label: String, subtitle: String, on: Boolean, onChange: (Boolean) -> Unit): View {
        val switch = Switch(context).apply {
            isChecked = on
            setOnCheckedChangeListener { _, value -> onChange(value) }
        }
        return LinearLayout(context).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(0, dp(10), 0, dp(10))
            addView(ImageView(context).apply { setImageDrawable(icon) }, LinearLayout.LayoutParams(dp(40), dp(40)).apply { rightMargin = dp(14) })
            addView(LinearLayout(context).apply {
                orientation = LinearLayout.VERTICAL
                addView(title(label))
                if (subtitle.isNotEmpty()) addView(secondary(subtitle))
            }, LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
            addView(switch)
            setOnClickListener { switch.toggle() }
        }
    }

    /** "5 minutes ago", "yesterday", in the phone's language. */
    fun ago(time: Long): String = DateUtils.getRelativeTimeSpanString(time, System.currentTimeMillis(), DateUtils.MINUTE_IN_MILLIS).toString()

    private fun color(light: String, dark: String) = Color.parseColor(if (night) dark else light)

    companion object {
        /** An app's name and icon; null when it is no longer installed. */
        fun app(context: Context, packageName: String): Pair<String, Drawable>? = try {
            val info = context.packageManager.getApplicationInfo(packageName, 0)
            context.packageManager.getApplicationLabel(info).toString() to context.packageManager.getApplicationIcon(info)
        } catch (e: PackageManager.NameNotFoundException) {
            null
        }
    }
}
