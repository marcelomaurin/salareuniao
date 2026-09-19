package br.com.maurinsoft.salareuniao

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

class UpdateCheckWorker(
    appContext: Context,
    workerParams: WorkerParameters
) : Worker(appContext, workerParams) {
    override fun doWork(): Result {
        val token = SecureTokenStore(applicationContext).load() ?: return Result.success()
        val session = SessionStore(applicationContext)

        return try {
            val info = ApiClient(session.apiBase, token).androidUpdate()
            if (
                info.enabled &&
                info.versionCode > BuildConfig.VERSION_CODE &&
                info.apkUrl.isNotBlank()
            ) {
                NotificationHelper.updateAvailable(
                    applicationContext,
                    info.versionCode,
                    info.version
                )
            }
            Result.success()
        } catch (_: Exception) {
            Result.retry()
        }
    }
}
