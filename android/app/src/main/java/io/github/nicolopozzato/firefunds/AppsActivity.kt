package io.github.nicolopozzato.firefunds

import android.app.Activity
import android.content.Intent
import android.graphics.drawable.Drawable
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import android.view.ViewGroup
import android.widget.BaseAdapter
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ListView
import java.text.Collator
import java.text.Normalizer

/**
 * Every app installed (those with an icon in the launcher), to choose the
 * ones to read before they post anything: the ones on first, then by name,
 * with a search. Nothing of the others ever leaves the phone.
 */
class AppsActivity : Activity() {
    private class App(val packageName: String, val label: String, val icon: Drawable)

    private lateinit var store: Store
    private lateinit var ui: Ui
    private var all: List<App> = emptyList()
    private var shown: List<App> = emptyList()
    private val adapter = object : BaseAdapter() {
        override fun getCount() = shown.size
        override fun getItem(position: Int) = shown[position]
        override fun getItemId(position: Int) = position.toLong()
        override fun getView(position: Int, convertView: View?, parent: ViewGroup): View {
            val app = shown[position]
            return ui.appRow(app.icon, app.label, "", store.isEnabled(app.packageName)) { on -> store.setEnabled(app.packageName, on) }
                .apply { setPadding(ui.dp(16), ui.dp(10), ui.dp(16), ui.dp(10)) }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = Store(this)
        ui = Ui(this)
        title = getString(R.string.apps_choose)
        actionBar?.setDisplayHomeAsUpEnabled(true)

        val search = EditText(this).apply {
            hint = getString(R.string.apps_search)
            isSingleLine = true
            addTextChangedListener(object : TextWatcher {
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
                override fun afterTextChanged(s: Editable?) = filter(s.toString())
            })
        }
        val list = ListView(this).apply {
            adapter = this@AppsActivity.adapter
            divider = null
        }
        setContentView(LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setBackgroundColor(ui.background)
            addView(search, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
                setMargins(ui.dp(16), ui.dp(8), ui.dp(16), ui.dp(4))
            })
            addView(list, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 1f))
        })

        // Icons take a moment on a phone with many apps.
        Thread {
            val launcher = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER)
            val collator = Collator.getInstance()
            val enabled = store.enabledApps()
            val apps = packageManager.queryIntentActivities(launcher, 0)
                .map { it.activityInfo.applicationInfo }
                .distinctBy { it.packageName }
                .filter { it.packageName != packageName }
                .map { App(it.packageName, packageManager.getApplicationLabel(it).toString(), packageManager.getApplicationIcon(it)) }
                .sortedWith(compareBy<App> { it.packageName !in enabled }.thenComparator { a, b -> collator.compare(a.label, b.label) })
            runOnUiThread {
                all = apps
                filter(search.text.toString())
            }
        }.start()
    }

    override fun onNavigateUp(): Boolean {
        finish()
        return true
    }

    private fun filter(query: String) {
        val words = fold(query).split(' ').filter { it.isNotEmpty() }
        shown = all.filter { app -> words.all { fold(app.label).contains(it) || app.packageName.contains(it) } }
        adapter.notifyDataSetChanged()
    }

    /** Lower case without accents, so "intesa" finds "Intesa Sanpaolo". */
    private fun fold(value: String) = Normalizer.normalize(value.lowercase(), Normalizer.Form.NFD).replace(Regex("\\p{M}"), "")
}
