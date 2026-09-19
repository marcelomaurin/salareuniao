package br.com.maurinsoft.salareuniao

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

class MeetingReminderWorker(
    appContext: Context,
    workerParams: WorkerParameters
) : Worker(appContext, workerParams) {
    override fun doWork(): Result {
        val roomId = inputData.getLong("room_id", 0L)
        val name = inputData.getString("room_name") ?: "Reunião"
        val startsAt = inputData.getString("starts_at") ?: ""
        if (roomId <= 0) return Result.failure()
        NotificationHelper.meetingReminder(applicationContext, roomId, name, startsAt)
        return Result.success()
    }
}
