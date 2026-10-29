package io.github.nicolopozzato.firefunds

import android.app.job.JobInfo
import android.app.job.JobParameters
import android.app.job.JobScheduler
import android.app.job.JobService
import android.content.ComponentName
import android.content.Context

/** Sends the queue once there is a network, retried by Android with a growing pause until it is empty. */
class SendJob : JobService() {

    override fun onStartJob(params: JobParameters): Boolean {
        Thread {
            val left = Server(Store(this)).sendQueue()
            jobFinished(params, left > 0)
        }.start()
        return true
    }

    override fun onStopJob(params: JobParameters): Boolean = true

    companion object {
        private const val ID = 1

        fun schedule(context: Context) {
            val job = JobInfo.Builder(ID, ComponentName(context, SendJob::class.java))
                .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                .setBackoffCriteria(30_000, JobInfo.BACKOFF_POLICY_EXPONENTIAL)
                .build()
            context.getSystemService(JobScheduler::class.java).schedule(job)
        }
    }
}
