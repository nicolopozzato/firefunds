package io.github.nicolopozzato.firefunds

import android.app.job.JobInfo
import android.app.job.JobParameters
import android.app.job.JobScheduler
import android.app.job.JobService
import android.content.ComponentName
import android.content.Context

/**
 * Every 15 minutes (Android's shortest), also after a restart: the reader
 * bound again if Android dropped it, the shade read for anything missed,
 * what waits sent.
 */
class Watchdog : JobService() {

    override fun onStartJob(params: JobParameters): Boolean {
        val reader = NotificationReader.connected
        if (reader == null) {
            NotificationReader.rebind(this)
        } else {
            reader.catchUp()
        }
        if (Store(this).queue().length() > 0) SendJob.schedule(this)
        return false
    }

    override fun onStopJob(params: JobParameters): Boolean = false

    companion object {
        private const val ID = 2

        fun schedule(context: Context) {
            val scheduler = context.getSystemService(JobScheduler::class.java)
            if (scheduler.getPendingJob(ID) != null) return
            val job = JobInfo.Builder(ID, ComponentName(context, Watchdog::class.java))
                .setPeriodic(15 * 60_000L)
                .setPersisted(true)
                .build()
            scheduler.schedule(job)
        }
    }
}
