package br.com.maurinsoft.salareuniao

import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

object UpdateScheduler {
    fun ensure(context: Context) {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()
        val request = PeriodicWorkRequestBuilder<UpdateCheckWorker>(
            12,
            TimeUnit.HOURS
        )
            .setConstraints(constraints)
            .addTag("android_update_check")
            .build()

        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            "android-update-check",
            ExistingPeriodicWorkPolicy.KEEP,
            request
        )
    }
}
