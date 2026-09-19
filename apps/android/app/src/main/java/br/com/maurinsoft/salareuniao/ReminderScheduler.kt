package br.com.maurinsoft.salareuniao

import android.content.Context
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.workDataOf
import java.time.Duration
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.concurrent.TimeUnit

object ReminderScheduler {
    private val formats = listOf(
        DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"),
        DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm")
    )

    private fun parse(value: String): LocalDateTime? {
        for (format in formats) {
            try {
                return LocalDateTime.parse(value, format)
            } catch (_: Exception) {
            }
        }
        return null
    }

    fun schedule(context: Context, agenda: List<AgendaInfo>, minutesBefore: Long = 10) {
        val manager = WorkManager.getInstance(context)
        val now = java.time.Instant.now()

        agenda.forEach { item ->
            val date = parse(item.startsAt) ?: return@forEach
            val target = date.minusMinutes(minutesBefore)
                .atZone(ZoneId.systemDefault())
                .toInstant()
            val delay = Duration.between(now, target).toMillis()
            if (delay <= 0) return@forEach

            val request = OneTimeWorkRequestBuilder<MeetingReminderWorker>()
                .setInitialDelay(delay, TimeUnit.MILLISECONDS)
                .setInputData(
                    workDataOf(
                        "room_id" to item.id,
                        "room_name" to item.name,
                        "starts_at" to item.startsAt
                    )
                )
                .addTag("meeting_reminder")
                .build()

            manager.enqueueUniqueWork(
                "meeting-reminder-" + item.id,
                ExistingWorkPolicy.REPLACE,
                request
            )
        }
    }
}
