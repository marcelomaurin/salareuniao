package br.com.maurinsoft.salareuniao

import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.Settings
import androidx.core.content.FileProvider
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

object ApkUpdateManager {
    fun canInstallPackages(activity: Activity): Boolean {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.O ||
            activity.packageManager.canRequestPackageInstalls()
    }

    fun requestInstallPermission(activity: Activity) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val intent = Intent(
                Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                Uri.parse("package:" + activity.packageName)
            )
            activity.startActivity(intent)
        }
    }

    fun download(
        activity: Activity,
        info: AndroidUpdateInfo,
        token: String,
        session: SessionStore
    ): File {
        val dir = activity.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS)
            ?: activity.filesDir
        if (!dir.exists()) dir.mkdirs()

        val file = File(dir, "SalaReuniaoAndroid-" + info.version + ".apk")
        val url = URL(info.apkUrl)
        val con = (url.openConnection() as HttpURLConnection).apply {
            connectTimeout = 15000
            readTimeout = 60000
            setRequestProperty("Accept", "application/vnd.android.package-archive")
            setRequestProperty("User-Agent", "SalaReuniaoAndroid/" + BuildConfig.VERSION_NAME)

            val apiHost = Uri.parse(session.apiBase).host
            if (apiHost != null && apiHost.equals(url.host, ignoreCase = true)) {
                setRequestProperty("Authorization", "Bearer " + token)
            }
        }

        if (con.responseCode !in 200..299) {
            throw ApiException("Falha no download do APK: HTTP " + con.responseCode)
        }

        con.inputStream.use { input ->
            file.outputStream().use { output ->
                input.copyTo(output)
            }
        }
        con.disconnect()

        if (file.length() <= 0) {
            file.delete()
            throw ApiException("APK baixado está vazio.")
        }
        return file
    }

    fun install(activity: Activity, file: File) {
        val uri = FileProvider.getUriForFile(
            activity,
            activity.packageName + ".files",
            file
        )
        val intent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        activity.startActivity(intent)
    }
}
